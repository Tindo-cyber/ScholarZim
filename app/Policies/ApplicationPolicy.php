<?php

namespace App\Policies;

use App\Models\Application;
use App\Models\User;

/**
 * Who may do what to an application.
 *
 * An application has two legitimate parties and they own different halves of
 * it: the applicant owns the submission and may withdraw it or answer a
 * question; the provider who posted the listing owns the decision. Neither may
 * reach into the other's half, which is the rule the state machine enforces on
 * the data and this policy enforces on the request.
 *
 * Administrators are deliberately absent from the per-record methods. They
 * oversee the platform through the reporting and audit screens, which read
 * aggregates; nothing in the product asks an administrator to open one
 * student's application, so nothing here grants it. Least privilege is easier
 * to keep than to retrofit.
 */
class ApplicationPolicy
{
    /** The applicant who submitted it, or the provider who posted the listing. */
    public function view(User $user, Application $application): bool
    {
        return $this->isApplicant($user, $application)
            || $this->isReviewingProvider($user, $application);
    }

    /** Only the student who submitted it. */
    public function withdraw(User $user, Application $application): bool
    {
        return $this->isApplicant($user, $application);
    }

    /** Only the student, answering a question the provider asked. */
    public function respond(User $user, Application $application): bool
    {
        return $this->isApplicant($user, $application);
    }

    /**
     * Only the provider who posted the listing. An applicant reaching this would
     * be deciding their own outcome.
     */
    public function review(User $user, Application $application): bool
    {
        return $this->isReviewingProvider($user, $application);
    }

    /** The attached document, readable by both parties to the application. */
    public function downloadDocument(User $user, Application $application): bool
    {
        return $this->view($user, $application);
    }

    /**
     * The applicant's results certificate, which lives on their profile rather
     * than on the application. The reviewing provider may read it because they
     * are assessing it; nobody else may, including other providers.
     */
    public function viewApplicantResults(User $user, Application $application): bool
    {
        return $this->isReviewingProvider($user, $application);
    }

    private function isApplicant(User $user, Application $application): bool
    {
        return $user->isApplicant() && $application->user_id === $user->user_id;
    }

    private function isReviewingProvider(User $user, Application $application): bool
    {
        return $user->isProvider()
            && $application->opportunity?->provider_user_id === $user->user_id;
    }
}
