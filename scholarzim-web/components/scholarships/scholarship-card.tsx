import Link from 'next/link';
import { Search } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent } from '@/components/ui/card';
import { Scholarship } from '@/lib/api';
import { appUrl } from '@/lib/utils';

function formatDeadline(deadline?: string) {
  if (!deadline) return 'Rolling deadline';
  const d = new Date(deadline);
  const days = Math.ceil((d.getTime() - Date.now()) / (1000 * 60 * 60 * 24));
  if (days < 0) return 'Closed';
  if (days <= 14) return `${days} days left`;
  return d.toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' });
}

export function ScholarshipCard({ scholarship }: { scholarship: Scholarship }) {
  const urgency = scholarship.deadline
    ? Math.ceil((new Date(scholarship.deadline).getTime() - Date.now()) / (1000 * 60 * 60 * 24))
    : null;

  return (
    <Card className="group flex h-full flex-col transition hover:-translate-y-0.5 hover:shadow-card-hover">
      <CardContent className="flex flex-1 flex-col p-5">
        <div className="mb-3 flex flex-wrap gap-2">
          {scholarship.fundingType && <Badge variant="default">{scholarship.fundingType}</Badge>}
          {scholarship.educationLevel && <Badge variant="secondary">{scholarship.educationLevel}</Badge>}
          {urgency !== null && urgency <= 14 && urgency >= 0 && (
            <Badge variant="warning">{formatDeadline(scholarship.deadline)}</Badge>
          )}
        </div>
        <h3 className="font-display text-lg font-bold leading-snug text-slate-900 group-hover:text-brand">
          {scholarship.title}
        </h3>
        <p className="mt-1 text-sm text-slate-500">{scholarship.providerName}</p>
        {scholarship.description && (
          <p className="mt-3 line-clamp-3 flex-1 text-sm leading-relaxed text-slate-600">
            {scholarship.description}
          </p>
        )}
        <div className="mt-4 flex items-center justify-between gap-3 border-t border-slate-100 pt-4">
          <span className="text-xs font-medium text-slate-500">{formatDeadline(scholarship.deadline)}</span>
          <Link
            href={`/scholarships/${scholarship.id}`}
            className="text-sm font-semibold text-brand hover:underline"
          >
            View details →
          </Link>
        </div>
      </CardContent>
    </Card>
  );
}

export function SearchHero({ defaultKeyword = '' }: { defaultKeyword?: string }) {
  return (
    <form action="/scholarships" method="get" className="relative mx-auto mt-8 max-w-2xl">
      <Search className="pointer-events-none absolute left-4 top-1/2 h-5 w-5 -translate-y-1/2 text-slate-400" />
      <input
        name="keyword"
        defaultValue={defaultKeyword}
        placeholder="Search scholarships, providers, fields…"
        className="h-14 w-full rounded-2xl border-0 bg-white/95 pl-12 pr-32 text-base shadow-lg ring-1 ring-white/20 placeholder:text-slate-400 focus:outline-none focus:ring-2 focus:ring-gold/60"
      />
      <button
        type="submit"
        className="absolute right-2 top-1/2 -translate-y-1/2 rounded-xl bg-gold px-5 py-2.5 text-sm font-bold text-slate-900 hover:bg-gold/90"
      >
        Search
      </button>
    </form>
  );
}

export function TrustFeatures() {
  const items = [
    { title: 'ScholarFit matching', desc: 'Explainable scores based on your education, field, and location.' },
    { title: 'Works on your phone', desc: 'Browse and apply from Android or iPhone — install as a web app.' },
    { title: 'Deadline intelligence', desc: 'Email and SMS reminders so rural students never miss a closing date.' },
  ];
  return (
    <section className="mx-auto max-w-6xl px-4 py-16">
      <div className="grid gap-6 md:grid-cols-3">
        {items.map((item) => (
          <Card key={item.title}>
            <CardContent className="p-6">
              <h3 className="font-display font-bold text-brand">{item.title}</h3>
              <p className="mt-2 text-sm leading-relaxed text-slate-600">{item.desc}</p>
            </CardContent>
          </Card>
        ))}
      </div>
    </section>
  );
}

export function CtaBand() {
  return (
    <section className="mx-auto max-w-6xl px-4 pb-16">
      <div className="hero-gradient rounded-3xl px-8 py-10 text-center text-white shadow-lg">
        <h2 className="font-display text-2xl font-bold">Ready to find your scholarship?</h2>
        <p className="mx-auto mt-2 max-w-xl text-white/85">Create a free account and get matched in minutes.</p>
        <div className="mt-6 flex flex-wrap justify-center gap-3">
          <a
            href={appUrl('/register')}
            className="inline-flex h-11 items-center rounded-xl bg-white px-6 text-sm font-bold text-brand hover:bg-white/90"
          >
            Create free account
          </a>
          <Link
            href="/scholarships"
            className="inline-flex h-11 items-center rounded-xl border border-white/40 px-6 text-sm font-semibold text-white hover:bg-white/10"
          >
            Browse scholarships
          </Link>
        </div>
      </div>
    </section>
  );
}
