import { api } from '@/lib/api';
import { useAsync } from '@/lib/useAsync';
import PageHeader from '@/components/layout/PageHeader';
import Section from '@/components/layout/Section';
import AsyncBoundary from '@/components/AsyncBoundary';
import ScholarshipCard from '@/components/ScholarshipCard';
import { LinkButton, StatTile, EmptyState } from '@/components/ui';

export default function HomePage() {
  const stats = useAsync(() => api.stats(), []);
  const latest = useAsync(() => api.scholarships({ size: 6 }), []);

  return (
    <>
      <PageHeader
        title="Find scholarships you actually qualify for"
        lead="Verified scholarships and academic opportunities for Zimbabwean students."
        actions={<LinkButton to="/scholarships">Browse scholarships</LinkButton>}
      />

      {/* Statistics are decorative context — a failure here should not take
          the page down, so this renders only on success. */}
      {stats.data && (
        <section className="sz-grid sz-grid--stats" aria-label="Platform statistics">
          <StatTile value={stats.data.activeScholarships} label="Active scholarships" />
          <StatTile value={stats.data.totalProviders} label="Verified providers" />
          <StatTile value={stats.data.totalApplicants} label="Students registered" />
          <StatTile value={stats.data.totalApplications} label="Applications submitted" />
        </section>
      )}

      <Section
        title="Latest opportunities"
        action={
          <LinkButton to="/scholarships" variant="ghost" size="sm">
            View all
          </LinkButton>
        }
      >
        <AsyncBoundary
          state={latest}
          isEmpty={(page) => page.content.length === 0}
          errorTitle="Could not load scholarships"
          empty={<EmptyState title="No scholarships are open right now." />}
        >
          {(page) => (
            <div className="sz-grid">
              {page.content.map((item) => (
                <ScholarshipCard key={item.id} scholarship={item} />
              ))}
            </div>
          )}
        </AsyncBoundary>
      </Section>
    </>
  );
}
