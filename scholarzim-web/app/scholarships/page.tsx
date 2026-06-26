import Link from 'next/link';
import { fetchScholarships } from '@/lib/api';
import { ScholarshipCard } from '@/components/scholarships/scholarship-card';
import { Input } from '@/components/ui/input';
import { Button } from '@/components/ui/button';

const LEVELS = ['Undergraduate', 'Honours', 'Masters', 'PhD', 'Diploma'];
const FIELDS = ['Computer Science', 'Medicine', 'Engineering', 'Accounting', 'Law', 'Agriculture'];
const COUNTRIES = ['Zimbabwe', 'United Kingdom', 'United States', 'South Africa', 'Any'];

type Props = { searchParams: Promise<Record<string, string | undefined>> };

export default async function ScholarshipsPage({ searchParams }: Props) {
  const params = await searchParams;
  const scholarships = await fetchScholarships({
    keyword: params.keyword,
    educationLevel: params.educationLevel,
    country: params.country,
    fieldOfStudy: params.fieldOfStudy,
  }).catch(() => []);

  return (
    <div className="mx-auto max-w-6xl px-4 py-10">
      <div className="mb-8">
        <h1 className="font-display text-3xl font-bold">Browse scholarships</h1>
        <p className="mt-2 text-slate-600">Filter by level, field, country, or keyword.</p>
      </div>

      <div className="grid gap-8 lg:grid-cols-[280px_1fr]">
        <aside className="h-fit rounded-2xl border border-slate-200 bg-white p-5 shadow-card">
          <form className="space-y-4">
            <div>
              <label className="mb-1.5 block text-sm font-semibold">Keyword</label>
              <Input name="keyword" defaultValue={params.keyword} placeholder="Search…" />
            </div>
            <div>
              <label className="mb-1.5 block text-sm font-semibold">Education level</label>
              <select
                name="educationLevel"
                defaultValue={params.educationLevel ?? ''}
                className="flex h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm"
              >
                <option value="">All levels</option>
                {LEVELS.map((l) => (
                  <option key={l} value={l}>{l}</option>
                ))}
              </select>
            </div>
            <div>
              <label className="mb-1.5 block text-sm font-semibold">Field of study</label>
              <select
                name="fieldOfStudy"
                defaultValue={params.fieldOfStudy ?? ''}
                className="flex h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm"
              >
                <option value="">All fields</option>
                {FIELDS.map((f) => (
                  <option key={f} value={f}>{f}</option>
                ))}
              </select>
            </div>
            <div>
              <label className="mb-1.5 block text-sm font-semibold">Country</label>
              <select
                name="country"
                defaultValue={params.country ?? ''}
                className="flex h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm"
              >
                <option value="">All countries</option>
                {COUNTRIES.map((c) => (
                  <option key={c} value={c}>{c}</option>
                ))}
              </select>
            </div>
            <Button type="submit" className="w-full">Apply filters</Button>
            <Link href="/scholarships" className="block text-center text-sm text-slate-500 hover:text-brand">
              Reset filters
            </Link>
          </form>
        </aside>

        <div>
          <p className="mb-4 text-sm text-slate-500">{scholarships.length} scholarship(s) found</p>
          {scholarships.length > 0 ? (
            <div className="grid gap-5 sm:grid-cols-2">
              {scholarships.map((s) => (
                <ScholarshipCard key={s.id} scholarship={s} />
              ))}
            </div>
          ) : (
            <div className="rounded-2xl border border-dashed border-slate-200 bg-white p-12 text-center">
              <p className="text-slate-600">No scholarships match your filters.</p>
              <Link href="/scholarships" className="mt-2 inline-block text-sm font-semibold text-brand">
                Clear filters
              </Link>
            </div>
          )}
        </div>
      </div>
    </div>
  );
}
