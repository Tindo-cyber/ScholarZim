<?php

namespace Tests\Unit;

use App\Models\ApplicantProfile;
use App\Support\EducationLevel;
use Tests\TestCase;

/**
 * ApplicantProfile::requiredDocumentTypes() - the document checklist by
 * education level, per the refined matrix: Primary asks only for their
 * results (the guardian-assisted Form 1 pathway, no recommendation letter),
 * O/A-Level asks for a results certificate plus a recommendation letter, a
 * continuing tertiary applicant asks for proof of study plus ID plus a
 * recommendation letter, and a postgraduate applicant - who already holds a
 * previous tertiary qualification rather than being mid-way through one -
 * asks for that certificate plus a recommendation letter, with no CV or ID
 * requested at any level.
 */
class ApplicantProfileDocumentMatrixTest extends TestCase
{
    private function profile(string $level): ApplicantProfile
    {
        return new ApplicantProfile(['education_level' => $level]);
    }

    public function test_primary_requires_results_but_no_recommendation_letter(): void
    {
        $required = $this->profile(EducationLevel::PRIMARY)->requiredDocumentTypes();

        $this->assertSame(['results'], $required);
        $this->assertNotContains('recommendation', $required, 'Primary does not ask for a recommendation letter');
    }

    public function test_o_level_requires_results_and_a_recommendation_letter(): void
    {
        $this->assertSame(
            ['results', 'recommendation'],
            $this->profile(EducationLevel::O_LEVEL)->requiredDocumentTypes()
        );
    }

    public function test_a_level_requires_results_and_a_recommendation_letter(): void
    {
        $this->assertSame(
            ['results', 'recommendation'],
            $this->profile(EducationLevel::A_LEVEL)->requiredDocumentTypes()
        );
    }

    public function test_undergraduate_requires_proof_of_study_id_and_a_recommendation_letter_but_no_cv(): void
    {
        $required = $this->profile(EducationLevel::UNDERGRADUATE)->requiredDocumentTypes();

        $this->assertSame(['transcript', 'passport', 'recommendation'], $required);
        $this->assertNotContains('cv', $required, 'a continuing undergraduate has no graduation certificate or CV to ask for');
    }

    public function test_diploma_and_certificate_follow_the_same_tertiary_set_as_undergraduate(): void
    {
        $this->assertSame(
            ['transcript', 'passport', 'recommendation'],
            $this->profile(EducationLevel::DIPLOMA)->requiredDocumentTypes()
        );
        $this->assertSame(
            ['transcript', 'passport', 'recommendation'],
            $this->profile(EducationLevel::CERTIFICATE)->requiredDocumentTypes()
        );
    }

    public function test_postgraduate_requires_the_previous_qualification_certificate_and_a_recommendation_letter_only(): void
    {
        $required = $this->profile(EducationLevel::POSTGRADUATE)->requiredDocumentTypes();

        $this->assertSame(['transcript', 'recommendation'], $required);
        $this->assertNotContains('passport', $required, 'a postgraduate applicant is not re-asked for an ID');
        $this->assertNotContains('cv', $required);
    }

    public function test_masters_and_phd_follow_the_same_postgraduate_set(): void
    {
        $this->assertSame(
            ['transcript', 'recommendation'],
            $this->profile(EducationLevel::MASTERS)->requiredDocumentTypes()
        );
        $this->assertSame(
            ['transcript', 'recommendation'],
            $this->profile(EducationLevel::PHD)->requiredDocumentTypes()
        );
    }

    /** The "Academic Certificate / Proof of Study" relabel, per the brief, covers both a current proof of study and a completed previous qualification. */
    public function test_the_transcript_label_reads_as_proof_of_study(): void
    {
        $this->assertSame('Academic Certificate / Proof of Study', ApplicantProfile::DOCUMENT_LABELS['transcript']);
    }
}
