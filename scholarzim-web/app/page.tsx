import Link from 'next/link';
import { fetchFeatured, fetchPublicStats } from '@/lib/api';
import { ScholarshipCard, SearchHero, TrustFeatures, CtaBand } from '@/components/scholarships/scholarship-card';

export default async function HomePage() {
  const [stats, featured] = await Promise.all([
    fetchPublicStats().catch(() => null),
    fetchFeatured(6).catch(() => []),
  ]);

  return (
    <>
      <section className="hero-gradient text-white">
        <div className="mx-auto max-w-6xl px-4 py-16 md:py-24">
          <p className="text-sm font-semibold uppercase tracking-wider text-white/70">Zimbabwe&apos;s scholarship platform</p>
          <h1 className="mt-3 max-w-3xl font-display text-4xl font-extrabold leading-tight md:text-5xl">
            Find funding for your future
          </h1>
          <p className="mt-4 max-w-2xl text-lg text-white/85">
            Discover scholarships from Chevening, CBZ, Higherlife, and more — matched to your profile and accessible on any phone.
          </p>
          <SearchHero />

          {stats && (
            <div className="mt-10 grid grid-cols-2 gap-3 md:grid-cols-4">
              {[
                { label: 'Active scholarships', value: stats.activeScholarships ?? stats.totalScholarships ?? 0 },
                { label: 'Students', value: stats.totalApplicants ?? 0 },
                { label: 'Providers', value: stats.totalProviders ?? 0 },
                { label: 'Applications', value: stats.totalApplications ?? 0 },
              ].map((s) => (
                <div key={s.label} className="rounded-2xl bg-white/10 p-4 backdrop-blur-sm">
                  <div className="font-display text-2xl font-bold">{s.value}</div>
                  <div className="text-sm text-white/75">{s.label}</div>
                </div>
              ))}
            </div>
          )}
        </div>
      </section>

      <section className="mx-auto max-w-6xl px-4 py-14">
        <div className="mb-8 flex items-end justify-between gap-4">
          <div>
            <h2 className="font-display text-2xl font-bold">Featured opportunities</h2>
            <p className="mt-1 text-slate-500">Hand-picked scholarships closing soon or newly posted.</p>
          </div>
          <Link href="/scholarships" className="text-sm font-semibold text-brand hover:underline">
            View all →
          </Link>
        </div>
        {featured.length > 0 ? (
          <div className="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
            {featured.map((s) => (
              <ScholarshipCard key={s.id} scholarship={s} />
            ))}
          </div>
        ) : (
          <p className="rounded-2xl border border-dashed border-slate-200 bg-white p-10 text-center text-slate-500">
            Scholarships loading from the API. Ensure Spring Boot is running on port 8080.
          </p>
        )}
      </section>

      <TrustFeatures />
      <CtaBand />
    </>
  );
}
