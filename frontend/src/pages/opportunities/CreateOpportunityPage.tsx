import PageHeader from '@/components/layout/PageHeader';
import PendingEndpoint from '@/components/PendingEndpoint';

export default function CreateOpportunityPage() {
  return (
    <>
      <PageHeader title="Post an opportunity" lead="Publish a new scholarship for students to apply to." />
      <PendingEndpoint
        endpoint="POST /api/provider/opportunities"
        thymeleafPath="/opportunities/create"
        description="Creating an opportunity involves server-side validation that has not been moved to REST."
      />
    </>
  );
}
