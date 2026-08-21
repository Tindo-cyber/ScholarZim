import PageHeader from '@/components/layout/PageHeader';
import PendingEndpoint from '@/components/PendingEndpoint';

export default function NotificationsPage() {
  return (
    <>
      <PageHeader title="Notifications" lead="Updates about your applications and opportunities." />
      <PendingEndpoint
        endpoint="GET /api/notifications"
        thymeleafPath="/notifications"
        description="The notification centre and its read/unread actions need a REST endpoint."
      />
    </>
  );
}
