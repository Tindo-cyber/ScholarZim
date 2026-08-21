import PageHeader from '@/components/layout/PageHeader';
import PendingEndpoint from '@/components/PendingEndpoint';

export default function MyApplicationsPage() {
  return (
    <>
      <PageHeader title="My applications" lead="Every scholarship you have applied for, and where each one stands." />
      <PendingEndpoint
        endpoint="GET /api/applicant/applications"
        thymeleafPath="/my-applications"
        description="Application history and status tracking are not exposed over REST yet."
      />
    </>
  );
}
