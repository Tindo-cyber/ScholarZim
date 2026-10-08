<?php

namespace Tests\Support;

use App\Models\AcademicQualification;
use App\Models\AcademicSubject;
use App\Models\ApplicantInstitution;
use App\Models\ApplicantProfile;
use App\Models\ApplicantProgramme;
use App\Models\Field;
use App\Models\Institution;
use App\Models\Opportunity;
use App\Models\OpportunityScope;
use App\Models\OpportunitySubjectRequirement;
use App\Models\Programme;
use App\Models\Role;
use App\Models\User;
use App\Services\ApplicantProfileService;
use App\Support\OpportunityModerationStatus;
use App\Support\OpportunityStatus;
use App\Support\RoleNames;
use Database\Seeders\AcademicQualificationSeeder;
use Database\Seeders\CatalogueSeeder;

/**
 * Builds the applicants and listings described in tests/Fixtures/scholarfit/*.json as real rows.
 *
 * The fixtures say WHAT is true of each person and each listing, in plain terms; this turns them into
 * the database rows the engine reads. Nothing here decides who is eligible for what - that is the
 * answer key's job (expected.json), written separately by hand.
 */
trait LoadsScholarFitFixtures
{
    private const FIXTURES = __DIR__ . '/../Fixtures/scholarfit/';

    /** @return array<string, mixed> */
    protected function fixture(string $name): array
    {
        return json_decode((string) file_get_contents(self::FIXTURES . $name . '.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @return array{applicants: array<string, ApplicantProfile>, listings: array<string, Opportunity>}
     */
    protected function loadScholarFitFixtures(): array
    {
        (new AcademicQualificationSeeder())->run();
        (new CatalogueSeeder())->load();

        $provider = User::create([
            'role_id' => Role::where('role_name', RoleNames::PROVIDER)->value('role_id'),
            'full_name' => 'Fixture Provider', 'email' => 'provider@fixtures.test',
            'password_hash' => bcrypt('ChangeMe123'), 'account_status' => 'ACTIVE', 'email_verified' => true,
        ]);

        $applicants = [];

        foreach ($this->fixture('applicants') as $key => $spec) {
            $applicants[$key] = $this->makeApplicant($key, $spec);
        }

        $listings = [];

        foreach ($this->fixture('listings') as $key => $spec) {
            $listings[$key] = $this->makeListing($provider, $spec);
        }

        return ['applicants' => $applicants, 'listings' => $listings];
    }

    private function makeApplicant(string $key, array $spec): ApplicantProfile
    {
        $user = User::create([
            'role_id' => Role::where('role_name', RoleNames::APPLICANT)->value('role_id'),
            'full_name' => $spec['name'], 'email' => strtolower($key) . '@fixtures.test',
            'password_hash' => bcrypt('ChangeMe123'), 'account_status' => 'ACTIVE', 'email_verified' => true,
        ]);

        $documents = $spec['documents'] ?? [];

        $profile = ApplicantProfile::create([
            'user_id' => $user->user_id,
            'education_level' => $spec['level'],
            'date_of_birth' => $spec['dob'] ?? null,
            'province' => $spec['province'] ?? null,
            'locality' => $spec['locality'] ?? null,
            'settlement_type' => $spec['settlement'] ?? null,
            'field_of_study' => $spec['field_of_study'] ?? null,
            'results_certificate_path' => in_array('results_certificate', $documents, true) ? 'fixtures/results.pdf' : null,
            'transcript_path' => in_array('transcript', $documents, true) ? 'fixtures/transcript.pdf' : null,
            'guardian_name' => $spec['level'] === 'PRIMARY' ? 'A Guardian' : null,
            'guardian_phone' => $spec['level'] === 'PRIMARY' ? '+263771234567' : null,
        ]);

        // Results go in the way an applicant's do, so the points are derived the real way.
        $rows = [];

        foreach ($spec['results'] ?? [] as $qualificationKey => $subjects) {
            $qualification = AcademicQualification::findByKey($qualificationKey);

            foreach ($subjects as $subject => $grade) {
                $rows[] = [
                    'qualification_id' => $qualification->id,
                    'subject_id' => AcademicSubject::where('qualification_id', $qualification->id)->where('name', $subject)->value('id'),
                    'result' => $grade,
                    'year' => 2025,
                ];
            }
        }

        if ($rows !== []) {
            app(ApplicantProfileService::class)->syncAcademicResults($user, $rows);
        }

        if (isset($spec['current'])) {
            ApplicantProgramme::create([
                'profile_id' => $profile->profile_id,
                'programme_id' => Programme::where('name', $spec['current']['programme'])->value('id'),
                'kind' => 'current',
                'institution_id' => Institution::where('code', $spec['current']['institution'] ?? '')->value('id'),
            ]);
        }

        foreach ($spec['intended'] ?? [] as $name) {
            ApplicantProgramme::create(['profile_id' => $profile->profile_id, 'programme_id' => Programme::where('name', $name)->value('id'), 'kind' => 'intended']);
        }

        foreach ($spec['applied'] ?? [] as $code) {
            ApplicantInstitution::create(['profile_id' => $profile->profile_id, 'institution_id' => Institution::where('code', $code)->value('id')]);
        }

        return $profile->fresh();
    }

    private function makeListing(User $provider, array $spec): Opportunity
    {
        $listing = Opportunity::create([
            'provider_user_id' => $provider->user_id,
            'provider_name' => $provider->full_name,
            'title' => $spec['title'],
            'description' => $spec['description'] ?? 'A fixture listing.',
            'education_level' => $spec['level'] ?? null,
            'min_academic_points' => $spec['min_points'] ?? null,
            'max_age' => $spec['max_age'] ?? null,
            'required_province' => $spec['province'] ?? null,
            'target_locality' => $spec['locality'] ?? null,
            'target_settlement_type' => $spec['settlement'] ?? null,
            'requires_results_certificate' => (bool) ($spec['requires_certificate'] ?? false),
            'funding_type' => 'Full Scholarship',
            'country' => 'Zimbabwe', 'target_country' => 'Zimbabwe',
            'deadline' => $spec['deadline'] ?? null,
            'status' => OpportunityStatus::ACTIVE,
            'moderation_status' => OpportunityModerationStatus::APPROVED,
            'submitted_at' => now()->subDay(),
            'created_at' => now(),
        ]);

        foreach ($spec['subjects'] ?? [] as $row) {
            $qualification = AcademicQualification::findByKey($row['qualification']);

            OpportunitySubjectRequirement::create([
                'opportunity_id' => $listing->opportunity_id,
                'qualification_id' => $qualification->id,
                'subject_id' => AcademicSubject::where('qualification_id', $qualification->id)->where('name', $row['subject'])->value('id'),
                'minimum_grade' => $row['grade'],
            ]);
        }

        foreach ($spec['scope']['programmes'] ?? [] as $name) {
            OpportunityScope::create(['opportunity_id' => $listing->opportunity_id, 'programme_id' => Programme::where('name', $name)->value('id')]);
        }

        foreach ($spec['scope']['fields'] ?? [] as $code) {
            OpportunityScope::create(['opportunity_id' => $listing->opportunity_id, 'field_id' => Field::where('code', $code)->value('id')]);
        }

        foreach ($spec['scope']['institutions'] ?? [] as $code) {
            OpportunityScope::create(['opportunity_id' => $listing->opportunity_id, 'institution_id' => Institution::where('code', $code)->value('id')]);
        }

        return $listing->fresh();
    }
}
