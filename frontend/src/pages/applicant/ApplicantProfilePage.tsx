import PageHeader from '@/components/layout/PageHeader';
import PendingEndpoint from '@/components/PendingEndpoint';

export default function ApplicantProfilePage() {
  return (
    <>
      <PageHeader title="Profile" lead="Your academic details and supporting documents." />
      <PendingEndpoint
        endpoint="GET + PUT /api/applicant/profile"
        thymeleafPath="/applicant/profile"
        description="Profile editing and document upload still run through the server-rendered form."
      />
    </>
  );
}
