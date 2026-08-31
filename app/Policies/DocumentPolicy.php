<?php

namespace App\Policies;

use App\Models\ApplicantProfile;
use App\Models\ProviderProfile;
use App\Models\User;
use App\Support\RoleNames;

/**
 * Uploaded files, which are the most sensitive thing the platform stores.
 *
 * Documents are not an Eloquent model here - they are paths hanging off a
 * profile or an application - so this policy is addressed by the record that
 * owns the file rather than by the file itself. That is deliberate: a path is
 * not an authorisation subject, and treating it as one is how a download
 * endpoint ends up trusting whatever it was handed.
 *
 * National IDs, passports and results certificates sit behind these checks, so
 * the rules are the narrowest in the codebase: a profile document is readable by
 * its owner alone, and a provider sees an applicant's certificate only through
 * an application to their own listing - never by browsing profiles.
 */
class DocumentPolicy
{
    /** One of the applicant's own profile documents. */
    public function viewOwnProfileDocument(User $user, ApplicantProfile $profile): bool
    {
        return $profile->user_id === $user->user_id;
    }

    /**
     * A provider reading an applicant's results certificate. Permitted only in
     * the context of an application to a listing they own - which the caller
     * establishes - and never as a standalone profile lookup.
     */
    public function viewApplicantDocumentViaApplication(User $user, ?int $reviewingProviderId): bool
    {
        return $user->isProvider()
            && $reviewingProviderId !== null
            && $reviewingProviderId === $user->user_id;
    }

    /**
     * A provider's registration certificate, which is how their legitimacy is
     * verified. Administrators only: it is evidence for a moderation decision,
     * not something the provider's applicants get to inspect.
     */
    public function viewProviderCertificate(User $user, ProviderProfile $profile): bool
    {
        return $user->roleName() === RoleNames::ADMIN
            || $profile->user_id === $user->user_id;
    }
}
