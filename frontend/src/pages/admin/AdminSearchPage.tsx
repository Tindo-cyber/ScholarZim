import PageHeader from '@/components/layout/PageHeader';
import PendingEndpoint from '@/components/PendingEndpoint';

export default function AdminSearchPage() {
  return (
    <>
      <PageHeader title="Search" lead="Find users, providers, and opportunities across the platform." />
      <PendingEndpoint
        endpoint="GET /api/admin/search"
        thymeleafPath="/admin/search"
        description="Cross-entity admin search is still server-rendered."
      />
    </>
  );
}
