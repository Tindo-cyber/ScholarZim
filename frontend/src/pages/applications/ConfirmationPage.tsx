import PageHeader from '@/components/layout/PageHeader';
import PendingEndpoint from '@/components/PendingEndpoint';

export default function ConfirmationPage() {
  return (
    <>
      <PageHeader title="Application submitted" lead="Your application has been received." />
      <PendingEndpoint
        endpoint="GET /api/applications/{id}"
        thymeleafPath="/my-applications"
        description="The confirmation receipt is rendered server-side after submission."
      />
    </>
  );
}
