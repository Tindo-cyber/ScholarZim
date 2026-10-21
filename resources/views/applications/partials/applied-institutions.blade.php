@php
    /**
     * "Institutions you have applied to or hold an offer from": asked of a school-leaver only when a scholarship is
     * limited to named institutions, and kept here rather than on the profile. Shown empty when nothing is on file and
     * with the saved list ticked when something is, so correcting it starts from the truth and never replaces several
     * institutions with one by accident.
     */
    $chosenInstitutions = array_map('intval', (array) old('profile.applied_institution_ids', $readiness->savedInstitutionIds()));
    $correcting = $readiness->correctsAppliedInstitutions();
@endphp
<x-limited-choice name="profile[applied_institution_ids]" error-key="profile.applied_institution_ids"
                  id="applied_institution_ids" noun="institutions"
                  :max="\App\Models\ApplicantInstitution::MAX"
                  label="Institutions you have applied to or hold an offer from"
                  :groups="$readiness->institutionGroups($chosenInstitutions)"
                  :hint="$correcting
                      ? 'None of your institutions is on the list for this scholarship. Change them if wrong; eligibility is checked again on save.'
                      : 'This scholarship is for students at certain institutions.'" />
