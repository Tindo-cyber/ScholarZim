import PageHeader from '@/components/layout/PageHeader';
import PendingEndpoint from '@/components/PendingEndpoint';

export default function ApplicantDashboardPage() {
  return (
    <>
      <PageHeader title="Dashboard" lead="Your applications, deadlines, and profile progress at a glance." />
      <PendingEndpoint
        endpoint="GET /api/applicant/dashboard"
        thymeleafPath="/applicant/dashboard"
        description="Dashboard summary counts and upcoming deadlines still come from the server-rendered page."
      />
    </>
  );
}
