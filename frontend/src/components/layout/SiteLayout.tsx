import { NavLink, Outlet, Link } from 'react-router-dom';
import { useSession } from '@/lib/session';
import { ExternalLinkButton } from '@/components/ui';
import LogoutButton from '@/components/LogoutButton';

/** Public site chrome: header, content, footer. */
export default function SiteLayout() {
  const { user } = useSession();

  return (
    <div className="sz-shell">
      <header className="sz-header">
        <div className="sz-container sz-header__inner">
          <Link to="/" className="sz-brand">
            <img src="/images/scholarzim-mark.svg" alt="" />
            ScholarZim
          </Link>

          <nav className="sz-nav" aria-label="Main">
            <NavLink to="/" end>
              Home
            </NavLink>
            <NavLink to="/scholarships">Scholarships</NavLink>

            {user.authenticated ? (
              <>
                <NavLink to={dashboardPath(user.role)}>Dashboard</NavLink>
                <LogoutButton />
              </>
            ) : (
              <>
                {/* Auth is still server-rendered, so these leave the SPA. */}
                <a href="/login">Sign in</a>
                <ExternalLinkButton href="/register">Get started</ExternalLinkButton>
              </>
            )}
          </nav>
        </div>
      </header>

      <main className="sz-main">
        <div className="sz-container">
          <Outlet />
        </div>
      </main>

      <footer className="sz-footer">
        <div className="sz-container">
          &copy; {new Date().getFullYear()} ScholarZim — scholarships and academic
          opportunities for Zimbabwean students.
        </div>
      </footer>
    </div>
  );
}

function dashboardPath(role: string | null): string {
  switch (role) {
    case 'ROLE_ADMIN':
      return '/admin/dashboard';
    case 'ROLE_PROVIDER':
      return '/provider/dashboard';
    default:
      return '/applicant/dashboard';
  }
}
