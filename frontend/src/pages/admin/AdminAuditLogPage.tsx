import PageHeader from '@/components/layout/PageHeader';
import PendingEndpoint from '@/components/PendingEndpoint';

export default function AdminAuditLogPage() {
  return (
    <>
      <PageHeader title="Audit log" lead="A record of security-relevant actions taken on the platform." />
      <PendingEndpoint
        endpoint="GET /api/admin/audit-log"
        thymeleafPath="/admin/audit-log"
        description="The paginated audit trail has no REST endpoint yet."
      />
    </>
  );
}
