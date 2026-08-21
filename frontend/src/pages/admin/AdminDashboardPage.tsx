import PageHeader from '@/components/layout/PageHeader';
import PendingEndpoint from '@/components/PendingEndpoint';

export default function AdminDashboardPage() {
  return (
    <>
      <PageHeader title="Admin dashboard" lead="Platform health, pending verifications, and recent activity." />
      <PendingEndpoint
        endpoint="GET /api/admin/dashboard"
        thymeleafPath="/admin/dashboard"
        description="Administrative summary data is not exposed over REST yet."
      />
    </>
  );
}
