import PageHeader from '@/components/layout/PageHeader';
import PendingEndpoint from '@/components/PendingEndpoint';

export default function ProviderApplicationsPage() {
  return (
    <>
      <PageHeader title="Applications received" lead="Applications submitted to your opportunities." />
      <PendingEndpoint
        endpoint="GET /api/provider/applications"
        thymeleafPath="/provider/applications"
        description="The provider review queue has no REST equivalent yet."
      />
    </>
  );
}
