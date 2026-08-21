import PageHeader from '@/components/layout/PageHeader';
import PendingEndpoint from '@/components/PendingEndpoint';

export default function OpportunitiesPage() {
  return (
    <>
      <PageHeader title="Opportunities" lead="Scholarships and academic opportunities you have posted." />
      <PendingEndpoint
        endpoint="GET /api/provider/opportunities"
        thymeleafPath="/opportunities"
        description="The authenticated opportunity list differs from the public catalogue and needs its own endpoint."
      />
    </>
  );
}
