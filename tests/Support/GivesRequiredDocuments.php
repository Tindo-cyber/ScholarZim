<?php

namespace Tests\Support;

use App\Models\ApplicantProfile;
use App\Models\Opportunity;
use App\Models\User;

/**
 * Makes an applicant "ready to apply" as far as documents go: stores a file reference for each document the checklist
 * says they must hold, for their level and (optionally) for one scholarship.
 *
 * Tests about eligibility, the level ladder or the application workflow use one-click apply and the service directly,
 * and both now refuse an applicant whose required documents are not on file (ApplicationService::submit). The required
 * set is read from ApplicantProfile::documentChecklist(), not copied here, so these tests follow the real rule.
 */
trait GivesRequiredDocuments
{
    /** @return array<int, string> the document types that were filled in */
    protected function giveRequiredDocuments(User $user, ?Opportunity $for = null): array
    {
        $profile = ApplicantProfile::where('user_id', $user->user_id)->firstOrFail();
        $given = [];

        foreach ($profile->documentChecklist($for)->missing() as $item) {
            $prefix = ApplicantProfile::DOCUMENT_TYPES[$item['type']];
            $uploadedAt = $prefix === 'results_certificate' ? 'results_uploaded_at' : $prefix . '_uploaded_at';

            $profile->forceFill([
                $prefix . '_path' => 'profiles/test/' . $user->user_id . '-' . $item['type'] . '.pdf',
                $prefix . '_filename' => $item['type'] . '.pdf',
                $uploadedAt => now(),
            ]);
            $given[] = $item['type'];
        }

        $profile->save();

        return $given;
    }
}
