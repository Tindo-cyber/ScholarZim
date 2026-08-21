import PageHeader from '@/components/layout/PageHeader';
import PendingEndpoint from '@/components/PendingEndpoint';

export default function AccountSecurityPage() {
  return (
    <>
      <PageHeader title="Security" lead="Password and notification preferences." />
      <PendingEndpoint
        endpoint="GET + PUT /api/account/security"
        thymeleafPath="/account/security"
        description="Password changes and notification toggles remain server-rendered form posts."
      />
    </>
  );
}
