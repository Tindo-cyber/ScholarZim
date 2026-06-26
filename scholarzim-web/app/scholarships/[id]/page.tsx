import Link from 'next/link';
import { notFound } from 'next/navigation';
import { fetchScholarship } from '@/lib/api';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent } from '@/components/ui/card';
import { appUrl } from '@/lib/utils';

type Props = { params: Promise<{ id: string }> };

export default async function ScholarshipDetailPage({ params }: Props) {
  const { id } = await params;
  const scholarship = await fetchScholarship(id).catch(() => null);
  if (!scholarship) notFound();

  const deadline = scholarship.deadline
    ? new Date(scholarship.deadline).toLocaleDateString('en-GB', {
        day: 'numeric',
        month: 'long',
        year: 'numeric',
      })
    : 'Rolling';

  return (
    <>
      <section className="hero-gradient py-10 text-white">
        <div className="mx-auto max-w-6xl px-4">
          <Link href="/scholarships" className="text-sm text-white/75 hover:text-white">
            ← Back to scholarships
          </Link>
          <h1 className="mt-4 font-display text-3xl font-bold md:text-4xl">{scholarship.title}</h1>
          <p className="mt-2 text-white/85">{scholarship.providerName}</p>
          <div className="mt-4 flex flex-wrap gap-2">
            {scholarship.fundingType && <Badge className="bg-white/15 text-white">{scholarship.fundingType}</Badge>}
            {scholarship.educationLevel && <Badge className="bg-white/15 text-white">{scholarship.educationLevel}</Badge>}
            {scholarship.country && <Badge className="bg-white/15 text-white">{scholarship.country}</Badge>}
          </div>
        </div>
      </section>

      <div className="mx-auto max-w-6xl px-4 py-10">
        <div className="grid gap-8 lg:grid-cols-[1fr_340px]">
          <div className="space-y-6">
            <Card>
              <CardContent className="p-6">
                <h2 className="font-display text-lg font-bold">About this scholarship</h2>
                <p className="mt-3 leading-relaxed text-slate-600">
                  {scholarship.description ?? 'No description provided.'}
                </p>
              </CardContent>
            </Card>
            <Card>
              <CardContent className="p-6">
                <h2 className="font-display text-lg font-bold">Eligibility</h2>
                <ul className="mt-4 space-y-3 text-sm text-slate-700">
                  <li><strong>Education level:</strong> {scholarship.educationLevel ?? 'Any'}</li>
                  <li><strong>Field of study:</strong> {scholarship.targetField ?? 'Any'}</li>
                  <li><strong>Country:</strong> {scholarship.country ?? 'Any'}</li>
                </ul>
              </CardContent>
            </Card>
          </div>

          <div className="lg:sticky lg:top-24 lg:self-start">
            <Card className="shadow-card">
              <CardContent className="space-y-4 p-6">
                <h2 className="font-display text-lg font-bold">Apply now</h2>
                <p className="text-sm text-slate-600"><strong>Deadline:</strong> {deadline}</p>
                <p className="text-sm text-slate-600">
                  <strong>Funding:</strong> {scholarship.fundingType ?? 'See details'}
                </p>
                <a
                  href={appUrl('/register')}
                  className="flex h-12 w-full items-center justify-center rounded-xl bg-brand text-sm font-bold text-white hover:bg-brand-dark"
                >
                  Register to apply
                </a>
                <a
                  href={appUrl('/login')}
                  className="flex h-11 w-full items-center justify-center rounded-xl border border-slate-200 text-sm font-semibold text-slate-700 hover:bg-slate-50"
                >
                  Already have an account?
                </a>
              </CardContent>
            </Card>
          </div>
        </div>
      </div>

      <div className="fixed bottom-0 left-0 right-0 border-t border-slate-200 bg-white p-3 pb-[calc(0.75rem+env(safe-area-inset-bottom))] md:hidden">
        <a
          href={appUrl('/register')}
          className="flex h-12 w-full items-center justify-center rounded-xl bg-brand font-bold text-white"
        >
          Register to apply
        </a>
      </div>
    </>
  );
}
