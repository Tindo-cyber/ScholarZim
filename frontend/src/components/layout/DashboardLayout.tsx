import { NavLink, Outlet, Link } from 'react-router-dom';
import { useSession } from '@/lib/session';
import LogoutButton from '@/components/LogoutButton';
import type { Role } from '@/lib/types';

interface NavItem {
  to: string;
  label: string;
}

/** Sidebar contents per role — the only place section navigation is defined. */
const NAV_BY_ROLE: Record<Role, NavItem[]> = {
  ROLE_APPLICANT: [
    { to: '/applicant/dashboard', label: 'Dashboard' },
    { to: '/applicant/recommendations', label: 'Recommendations' },
    { to: '/applicant/saved', label: 'Saved' },
    { to: '/my-applications', label: 'My applications' },
    { to: '/applicant/profile', label: 'Profile' },
  ],
  ROLE_PROVIDER: [
    { to: '/provider/dashboard', label: 'Dashboard' },
    { to: '/opportunities', label: 'Opportunities' },
    { to: '/opportunities/create', label: 'Post opportunity' },
    { to: '/provider/applications', label: 'Applications' },
  ],
  ROLE_ADMIN: [
    { to: '/admin/dashboard', label: 'Dashboard' },
    { to: '/admin/search', label: 'Search' },
    { to: '/admin/analytics', label: 'Analytics' },
    { to: '/admin/reports', label: 'Reports' },
    { to: '/admin/audit-log', label: 'Audit log' },
    { to: '/admin/users/create', label: 'Create user' },
  ],
};

/** Shell for every signed-in view: top bar, role-aware sidebar, content area. */
export default function DashboardLayout() {
  const { user } = useSession();
  const items = user.role ? NAV_BY_ROLE[user.role] : [];

  return (
    <div className="sz-shell">
      <header className="sz-header">
        <div className="sz-container sz-header__inner">
          <Link to="/" className="sz-brand">
            <img src="/images/scholarzim-mark.svg" alt="" />
            ScholarZim
          </Link>

          <nav className="sz-nav" aria-label="Account">
            <NavLink to="/notifications">Notifications</NavLink>
            <NavLink to="/account/security">Account</NavLink>
            {user.fullName && <span className="sz-user-chip">{user.fullName}</span>}
            <LogoutButton />
          </nav>
        </div>
      </header>

      <div className="sz-container sz-dashboard">
        <aside className="sz-sidebar">
          <nav aria-label="Sections">
            <ul className="sz-sidebar__list">
              {items.map((item) => (
                <li key={item.to}>
                  <NavLink to={item.to} end>
                    {item.label}
                  </NavLink>
                </li>
              ))}
            </ul>
          </nav>
        </aside>

        <main className="sz-dashboard__main">
          <Outlet />
        </main>
      </div>
    </div>
  );
}
