import PageHeader from '@/components/layout/PageHeader';
import PendingEndpoint from '@/components/PendingEndpoint';

export default function AdminAnalyticsPage() {
  return (
    <>
      <PageHeader title="Analytics" lead="Platform trends across users, opportunities, and applications." />
      <PendingEndpoint
        endpoint="GET /api/admin/analytics"
        thymeleafPath="/admin/analytics"
        description="Analytics aggregates are computed for the server-rendered view only."
      />
    </>
  );
}
