import { api } from '@/lib/api';
import { useAsync } from '@/lib/useAsync';
import { formatDeadline } from '@/lib/format';
import PageHeader from '@/components/layout/PageHeader';
import AsyncBoundary from '@/components/AsyncBoundary';
import {
  Badge,
  Card,
  CardTitle,
  CardMeta,
  CardFooter,
  EmptyState,
  LinkButton,
} from '@/components/ui';

/** ScholarFit matches. Backed by GET /api/applicant/recommendations. */
export default function RecommendationsPage() {
  const state = useAsync(() => api.recommendations(), []);

  return (
    <>
      <PageHeader
        title="Recommended for you"
        lead="Scholarships ranked by how well they match your profile."
      />

      <AsyncBoundary
        state={state}
        isEmpty={(page) => page.content.length === 0}
        errorTitle="Could not load your recommendations"
        empty={
          <EmptyState
            title="No matches yet"
            description="Completing your profile improves recommendations."
            action={<LinkButton to="/applicant/profile">Update profile</LinkButton>}
          />
        }
      >
        {(page) => (
          <div className="sz-grid">
            {page.content.map(({ scholarship, matchScore }) => (
              <Card key={scholarship.id}>
                <CardFooter>
                  <Badge tone="info">{Math.round(matchScore)}% match</Badge>
                </CardFooter>
                <CardTitle to={`/scholarships/${scholarship.id}`}>
                  {scholarship.title}
                </CardTitle>
                <CardMeta>{scholarship.providerName}</CardMeta>
                <CardMeta>{formatDeadline(scholarship.deadline)}</CardMeta>
              </Card>
            ))}
          </div>
        )}
      </AsyncBoundary>
    </>
  );
}
