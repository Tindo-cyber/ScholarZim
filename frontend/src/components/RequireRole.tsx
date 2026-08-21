import type { ReactNode } from 'react';
import { Outlet } from 'react-router-dom';
import { useSession } from '@/lib/session';
import { ErrorState, ExternalLinkButton, Spinner } from '@/components/ui';
import type { Role } from '@/lib/types';

/**
 * Client-side route guard. Redirects to the Thymeleaf login when signed out,
 * and shows a plain refusal when signed in as the wrong role. This is a UX
 * affordance only — the server enforces the real rules on every request.
 *
 * Wraps `children` when given one page, and falls back to `<Outlet />` so it
 * can also sit on a layout route guarding a whole section.
 */
export default function RequireRole({
  roles,
  children,
}: {
  roles: Role[];
  children?: ReactNode;
}) {
  const { user, loading, hasRole } = useSession();

  if (loading) {
    return <Spinner label="Checking your session" />;
  }

  if (!user.authenticated) {
    const target = `${window.location.pathname}${window.location.search}`;
    window.location.assign(`/login?redirect=${encodeURIComponent(target)}`);
    return null;
  }

  if (!hasRole(...roles)) {
    return (
      <ErrorState
        title="Not available for your account"
        description="This page is for a different account type."
        action={
          <ExternalLinkButton href="/dashboard" variant="ghost">
            Go to your dashboard
          </ExternalLinkButton>
        }
      />
    );
  }

  return <>{children ?? <Outlet />}</>;
}
