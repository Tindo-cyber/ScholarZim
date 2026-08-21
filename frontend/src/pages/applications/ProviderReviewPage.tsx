import PageHeader from '@/components/layout/PageHeader';
import PendingEndpoint from '@/components/PendingEndpoint';

export default function ProviderReviewPage() {
  return (
    <>
      <PageHeader title="Review application" lead="Assess this applicant and record a decision." />
      <PendingEndpoint
        endpoint="GET + POST /api/provider/applications/{id}"
        thymeleafPath="/provider/applications"
        description="Approve, reject, and request-documents actions are still server-rendered form posts."
      />
    </>
  );
}
