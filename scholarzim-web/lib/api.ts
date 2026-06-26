export type PlatformStats = {
  totalApplicants?: number;
  totalProviders?: number;
  totalScholarships?: number;
  activeScholarships?: number;
  totalApplications?: number;
};

export type Scholarship = {
  id: number;
  title: string;
  description?: string;
  providerName?: string;
  educationLevel?: string;
  fundingType?: string;
  country?: string;
  targetField?: string;
  deadline?: string;
  status?: string;
};

export type ScholarshipSearchParams = {
  keyword?: string;
  educationLevel?: string;
  country?: string;
  fieldOfStudy?: string;
  provider?: string;
};

const API_BASE = process.env.NEXT_PUBLIC_API_BASE ?? 'http://localhost:8080';

function buildUrl(path: string, params?: Record<string, string | undefined>) {
  const url = new URL(`${API_BASE.replace(/\/$/, '')}${path}`);
  if (params) {
    Object.entries(params).forEach(([k, v]) => {
      if (v) url.searchParams.set(k, v);
    });
  }
  return url.toString();
}

export async function fetchPublicStats(): Promise<PlatformStats> {
  const res = await fetch(buildUrl('/api/public/stats'), { next: { revalidate: 60 } });
  if (!res.ok) throw new Error('Failed to load stats');
  return res.json();
}

export async function fetchScholarships(params?: ScholarshipSearchParams): Promise<Scholarship[]> {
  const res = await fetch(
    buildUrl('/api/public/scholarships', {
      keyword: params?.keyword,
      educationLevel: params?.educationLevel,
      country: params?.country,
      fieldOfStudy: params?.fieldOfStudy,
      provider: params?.provider,
    }),
    { next: { revalidate: 30 } }
  );
  if (!res.ok) throw new Error('Failed to load scholarships');
  return res.json();
}

export async function fetchScholarship(id: string): Promise<Scholarship> {
  const res = await fetch(buildUrl(`/api/public/scholarships/${id}`), { next: { revalidate: 30 } });
  if (!res.ok) throw new Error('Scholarship not found');
  return res.json();
}

export async function fetchFeatured(limit = 6): Promise<Scholarship[]> {
  const all = await fetchScholarships();
  return all.slice(0, limit);
}
