/**
 * Same-origin fetch wrapper. The SPA is served by Spring, so the existing
 * session cookie authenticates every call and no CORS or token storage is
 * involved — the only thing to add by hand is the CSRF header on writes.
 */
import type {
  CurrentUser,
  Opportunity,
  PageResult,
  PlatformStats,
  Recommendation,
  ScholarshipSearch,
} from './types';

/** Shape returned by the save/unsave endpoints. */
export interface StatusResponse {
  status: string;
}

export class ApiError extends Error {
  constructor(
    readonly status: number,
    message: string,
  ) {
    super(message);
    this.name = 'ApiError';
  }
}

/**
 * Reads the CSRF token Spring writes to the XSRF-TOKEN cookie. Exported because
 * plain form POSTs that leave the SPA (logout, in particular) must carry it as
 * a hidden _csrf field or Spring Security rejects them.
 */
export function csrfToken(): string | null {
  const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/);
  return match ? decodeURIComponent(match[1]) : null;
}

function buildQuery(params: Record<string, unknown>): string {
  const query = new URLSearchParams();
  for (const [key, value] of Object.entries(params)) {
    if (value !== undefined && value !== null && value !== '') {
      query.set(key, String(value));
    }
  }
  const serialised = query.toString();
  return serialised ? `?${serialised}` : '';
}

async function request<T>(path: string, init: RequestInit = {}): Promise<T> {
  const method = (init.method ?? 'GET').toUpperCase();
  const headers = new Headers(init.headers);
  headers.set('Accept', 'application/json');

  if (method !== 'GET' && method !== 'HEAD') {
    if (!headers.has('Content-Type')) {
      headers.set('Content-Type', 'application/json');
    }
    const token = csrfToken();
    if (token) headers.set('X-XSRF-TOKEN', token);
  }

  const response = await fetch(path, {
    ...init,
    headers,
    credentials: 'same-origin',
  });

  // Session expired or not signed in. Thymeleaf still owns /login during the
  // migration, so hand the browser over rather than rendering a React login.
  if (response.status === 401 || response.status === 403) {
    // /api/me is an identity probe — it answers anonymously, and bouncing to
    // /login on it would fight the signed-out shell it exists to enable.
    if (!path.startsWith('/api/public/') && path !== '/api/me') {
      window.location.assign(`/login?redirect=${encodeURIComponent(window.location.pathname)}`);
    }
    throw new ApiError(response.status, 'Not authorised');
  }

  if (!response.ok) {
    throw new ApiError(response.status, `Request failed: ${response.status}`);
  }

  // 204, and any other empty body, would blow up response.json().
  if (response.status === 204) return undefined as unknown as T;
  const body = await response.text();
  return (body ? JSON.parse(body) : undefined) as T;
}

export const api = {
  me: () => request<CurrentUser>('/api/me'),

  stats: () => request<PlatformStats>('/api/public/stats'),

  scholarships: (search: ScholarshipSearch = {}) =>
    request<PageResult<Opportunity>>(
      `/api/public/scholarships${buildQuery({ ...search })}`,
    ),

  scholarship: (id: number | string) =>
    request<Opportunity>(`/api/public/scholarships/${id}`),

  // Applicant endpoints require an authenticated APPLICANT session.
  recommendations: (page = 0, size = 20) =>
    request<PageResult<Recommendation>>(
      `/api/applicant/recommendations${buildQuery({ page, size })}`,
    ),

  saved: (page = 0, size = 20) =>
    request<PageResult<Opportunity>>(`/api/applicant/saved${buildQuery({ page, size })}`),

  save: (id: number) =>
    request<StatusResponse>(`/api/applicant/saved/${id}`, { method: 'POST' }),

  unsave: (id: number) =>
    request<StatusResponse>(`/api/applicant/saved/${id}`, { method: 'DELETE' }),
};
