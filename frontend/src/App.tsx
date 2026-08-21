import { Route, Routes } from 'react-router-dom';
import SiteLayout from '@/components/layout/SiteLayout';
import DashboardLayout from '@/components/layout/DashboardLayout';
import RequireRole from '@/components/RequireRole';

import HomePage from '@/pages/public/HomePage';
import ScholarshipsPage from '@/pages/public/ScholarshipsPage';
import ScholarshipDetailPage from '@/pages/public/ScholarshipDetailPage';

import ApplicantDashboardPage from '@/pages/applicant/ApplicantDashboardPage';
import ApplicantProfilePage from '@/pages/applicant/ApplicantProfilePage';
import RecommendationsPage from '@/pages/applicant/RecommendationsPage';
import SavedScholarshipsPage from '@/pages/applicant/SavedScholarshipsPage';

import MyApplicationsPage from '@/pages/applications/MyApplicationsPage';
import ApplicationWizardPage from '@/pages/applications/ApplicationWizardPage';
import ConfirmationPage from '@/pages/applications/ConfirmationPage';
import ProviderApplicationsPage from '@/pages/applications/ProviderApplicationsPage';
import ProviderReviewPage from '@/pages/applications/ProviderReviewPage';

import ProviderDashboardPage from '@/pages/provider/ProviderDashboardPage';
import OpportunitiesPage from '@/pages/opportunities/OpportunitiesPage';
import CreateOpportunityPage from '@/pages/opportunities/CreateOpportunityPage';

import AdminDashboardPage from '@/pages/admin/AdminDashboardPage';
import AdminSearchPage from '@/pages/admin/AdminSearchPage';
import AdminAnalyticsPage from '@/pages/admin/AdminAnalyticsPage';
import AdminReportsPage from '@/pages/admin/AdminReportsPage';
import AdminAuditLogPage from '@/pages/admin/AdminAuditLogPage';
import AdminCreateUserPage from '@/pages/admin/AdminCreateUserPage';

import AccountSecurityPage from '@/pages/account/AccountSecurityPage';
import NotificationsPage from '@/pages/notifications/NotificationsPage';
import NotFoundPage from '@/pages/NotFoundPage';

/**
 * Routes mirror the Thymeleaf URLs exactly, offset by the /app basename — so
 * /app/applicant/saved is the React counterpart of /applicant/saved. Porting a
 * page then means pointing the old server route at the new one, with no URL
 * redesign to reason about.
 *
 * Auth (/login, /register, password reset) is intentionally absent: it stays
 * server-rendered until the rest of the migration is done.
 */
export default function App() {
  return (
    <Routes>
      {/* Public */}
      <Route element={<SiteLayout />}>
        <Route index element={<HomePage />} />
        <Route path="scholarships" element={<ScholarshipsPage />} />
        <Route path="scholarships/:id" element={<ScholarshipDetailPage />} />
        <Route path="*" element={<NotFoundPage />} />
      </Route>

      {/* Signed-in areas share the sidebar shell */}
      <Route element={<DashboardLayout />}>
        {/* Any authenticated role */}
        <Route
          path="notifications"
          element={
            <RequireRole roles={['ROLE_APPLICANT', 'ROLE_PROVIDER', 'ROLE_ADMIN']}>
              <NotificationsPage />
            </RequireRole>
          }
        />
        <Route
          path="account/security"
          element={
            <RequireRole roles={['ROLE_APPLICANT', 'ROLE_PROVIDER', 'ROLE_ADMIN']}>
              <AccountSecurityPage />
            </RequireRole>
          }
        />

        {/* Applicant */}
        <Route
          element={<RequireRole roles={['ROLE_APPLICANT']} />}
        >
          <Route path="applicant/dashboard" element={<ApplicantDashboardPage />} />
          <Route path="applicant/profile" element={<ApplicantProfilePage />} />
          <Route path="applicant/recommendations" element={<RecommendationsPage />} />
          <Route path="applicant/saved" element={<SavedScholarshipsPage />} />
          <Route path="my-applications" element={<MyApplicationsPage />} />
          <Route path="apply/:opportunityId" element={<ApplicationWizardPage />} />
          <Route
            path="applications/:applicationId/confirmation"
            element={<ConfirmationPage />}
          />
        </Route>

        {/* Provider */}
        <Route element={<RequireRole roles={['ROLE_PROVIDER']} />}>
          <Route path="provider/dashboard" element={<ProviderDashboardPage />} />
          <Route path="provider/applications" element={<ProviderApplicationsPage />} />
          <Route path="provider/applications/:id" element={<ProviderReviewPage />} />
          <Route path="opportunities" element={<OpportunitiesPage />} />
          <Route path="opportunities/create" element={<CreateOpportunityPage />} />
        </Route>

        {/* Admin */}
        <Route element={<RequireRole roles={['ROLE_ADMIN']} />}>
          <Route path="admin/dashboard" element={<AdminDashboardPage />} />
          <Route path="admin/search" element={<AdminSearchPage />} />
          <Route path="admin/analytics" element={<AdminAnalyticsPage />} />
          <Route path="admin/reports" element={<AdminReportsPage />} />
          <Route path="admin/audit-log" element={<AdminAuditLogPage />} />
          <Route path="admin/users/create" element={<AdminCreateUserPage />} />
        </Route>
      </Route>
    </Routes>
  );
}
