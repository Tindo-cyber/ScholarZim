// Mirrors the backend DTOs in com.scholarzim.dto. Keep these in sync when the
// Java records change — the OpenAPI spec at /v3/api-docs is the source of truth.

export interface Opportunity {
  id: number;
  title: string;
  description: string;
  providerName: string;
  educationLevel: string;
  fundingType: string;
  country: string;
  targetField: string;
  /** ISO date, e.g. "2026-09-30" */
  deadline: string | null;
  status: string;
}

export interface PageResult<T> {
  content: T[];
  page: number;
  size: number;
  totalPages: number;
  totalElements: number;
}

export interface PlatformStats {
  totalScholarships: number;
  activeScholarships: number;
  totalApplicants: number;
  totalProviders: number;
  totalApplications: number;
}

export type Role = 'ROLE_APPLICANT' | 'ROLE_PROVIDER' | 'ROLE_ADMIN';

/** From /api/me — anonymous callers get authenticated:false, not a 401. */
export interface CurrentUser {
  authenticated: boolean;
  userId: number | null;
  fullName: string | null;
  email: string | null;
  role: Role | null;
  superAdmin: boolean;
}

/** One ScholarFit match from /api/applicant/recommendations. */
export interface Recommendation {
  scholarship: Opportunity;
  matchScore: number;
  breakdown: Record<string, unknown>;
}

export interface ScholarshipSearch {
  educationLevel?: string;
  country?: string;
  fieldOfStudy?: string;
  provider?: string;
  keyword?: string;
  fundingType?: string;
  /** ISO date */
  deadlineBefore?: string;
  page?: number;
  size?: number;
}
