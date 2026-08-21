import PageHeader from '@/components/layout/PageHeader';
import PendingEndpoint from '@/components/PendingEndpoint';

export default function ApplicationWizardPage() {
  return (
    <>
      <PageHeader title="Apply" lead="Complete each step to submit your application." />
      <PendingEndpoint
        endpoint="GET + POST /api/applications"
        thymeleafPath="/my-applications"
        description="The multi-step wizard handles file uploads and server-side validation, so it is the last thing to port."
      />
    </>
  );
}
