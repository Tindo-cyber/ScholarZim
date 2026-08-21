import PageHeader from '@/components/layout/PageHeader';
import PendingEndpoint from '@/components/PendingEndpoint';

export default function ProviderDashboardPage() {
  return (
    <>
      <PageHeader title="Provider dashboard" lead="Your posted opportunities and incoming applications." />
      <PendingEndpoint
        endpoint="GET /api/provider/dashboard"
        thymeleafPath="/provider/dashboard"
        description="Provider summary statistics are not exposed over REST yet."
      />
    </>
  );
}
