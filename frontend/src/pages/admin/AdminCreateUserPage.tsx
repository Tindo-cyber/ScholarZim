import PageHeader from '@/components/layout/PageHeader';
import PendingEndpoint from '@/components/PendingEndpoint';

export default function AdminCreateUserPage() {
  return (
    <>
      <PageHeader title="Create user" lead="Add an administrator or provider account." />
      <PendingEndpoint
        endpoint="POST /api/admin/users"
        thymeleafPath="/admin/users/create"
        description="User creation triggers email verification server-side and is not exposed over REST."
      />
    </>
  );
}
