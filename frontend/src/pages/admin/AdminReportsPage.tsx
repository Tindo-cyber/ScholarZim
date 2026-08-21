import PageHeader from '@/components/layout/PageHeader';
import PendingEndpoint from '@/components/PendingEndpoint';

export default function AdminReportsPage() {
  return (
    <>
      <PageHeader title="Reports" lead="Generate PDF and Excel exports." />
      <PendingEndpoint
        endpoint="GET /api/admin/reports"
        thymeleafPath="/admin/reports"
        description="Report downloads already stream from /admin/reports/*.pdf and *.xlsx; only the listing page needs porting."
      />
    </>
  );
}
