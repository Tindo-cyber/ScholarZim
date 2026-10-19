<?php

namespace Database\Seeders;

use App\Models\AcademicQualification;
use App\Models\AcademicResult;
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
use App\Models\ProviderProfile;
use App\Models\Role;
use App\Models\User;
use App\Services\ScholarFit\AcademicRecord;
use App\Services\ScholarFit\EligibilityEvaluator;
use App\Services\ScholarFit\EligibilityResult;
use App\Services\ScholarFit\Taxonomy\SettlementType;
use App\Support\Academic\AcademicCatalogue;
use App\Support\AccountStatus;
use App\Support\Countries;
use App\Support\EducationLevel;
use App\Support\FormOptions;
use App\Support\Gender;
use App\Support\OpportunityModerationStatus;
use App\Support\OpportunityStatus;
use App\Support\ProviderOrgType;
use App\Support\RoleNames;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * Extra accounts for trying ScholarFit by hand: ten applicants and three providers, with listings built so that each
 * applicant is eligible for some, refused by others for a stated reason, and asked for information by a few.
 *
 * Opt-in, never part of DatabaseSeeder (so the counts the rest of the suite relies on do not move):
 *
 *     php artisan db:seed --class=ScholarFitTestSeeder
 *
 * Run `php artisan db:seed` first - this needs the roles, the academic qualifications and the programme catalogue.
 * Every account uses the password ChangeMe123, so like DatabaseSeeder it refuses to run in production. It is safe to
 * run again: accounts and listings are matched by email and title and reset to what is written here.
 *
 * It finishes by printing who ScholarFit says is eligible for what. That table is the point: read it against the
 * "what it tests" notes on each listing below.
 */
class ScholarFitTestSeeder extends Seeder
{
    private const PASSWORD = 'ChangeMe123';

    public function run(): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException('ScholarFitTestSeeder creates accounts with a published password and will not run in production.');
        }

        if (Role::where('role_name', RoleNames::APPLICANT)->doesntExist() || Programme::doesntExist() || AcademicQualification::doesntExist()) {
            throw new RuntimeException('Run `php artisan db:seed` first: this needs the roles, academic qualifications and programme catalogue.');
        }

        $applicants = $this->applicants();
        $providers = $this->providers();
        $this->listings($providers);

        $this->command?->info('Seeded ' . count($applicants) . ' applicants and ' . count($providers) . ' providers (password ' . self::PASSWORD . ').');
        $this->report(array_values($applicants));
    }

    // ------------------------------------------------------------ providers --

    /** @return array<string, User> keyed by a short name used below */
    private function providers(): array
    {
        $make = fn (string $email, string $name, string $phone, string $type, string $reg, ?string $website, bool $confirmed) => $this->provider($email, $name, $phone, $type, $reg, $website, $confirmed);

        return [
            'stem' => $make('fit.provider.stem@scholarzim.co.zw', 'Zambezi STEM Foundation', '0242810001', ProviderOrgType::FOUNDATION, 'MA 2201/2018', 'https://zambezi-stem.example.org', true),
            'regional' => $make('fit.provider.regional@scholarzim.co.zw', 'Mutare Regional Education Trust', '0242810002', ProviderOrgType::NGO, 'PVO 77/2019', 'https://mutaretrust.example.org', false),
            'open' => $make('fit.provider.open@scholarzim.co.zw', 'Open Doors Bursary Fund', '0242810003', ProviderOrgType::FOUNDATION, 'MA 3310/2020', null, false),
        ];
    }

    private function provider(string $email, string $name, string $phone, string $type, string $registration, ?string $website, bool $confirmed): User
    {
        $user = User::updateOrCreate(['email' => $email], [
            'role_id' => Role::where('role_name', RoleNames::PROVIDER)->value('role_id'),
            'full_name' => $name,
            'phone' => $phone,
            'password_hash' => Hash::make(self::PASSWORD),
            'account_status' => AccountStatus::ACTIVE,
            'email_verified' => true,
        ]);

        ProviderProfile::updateOrCreate(['user_id' => $user->user_id], [
            'organisation_type' => $type,
            'registration_country' => Countries::ZIMBABWE,
            'registration_number' => $registration,
            'contact_name' => 'Test Contact',
            'contact_role' => 'Director',
            'certificate_path' => 'provider-certificates/demo-certificate.pdf',
            'certificate_filename' => 'registration-certificate.pdf',
            'website' => $website,
            'website_verified_at' => $confirmed ? Carbon::now()->subMonth() : null,
            'submitted_at' => Carbon::now()->subMonths(4),
            'reviewed_at' => Carbon::now()->subMonths(4)->addDay(),
            'reviewed_by' => config('scholarzim.admin.email'),
        ]);

        return $user;
    }

    // ----------------------------------------------------------- applicants --

    /**
     * @return array<string, User>
     *
     * Ten people, each chosen to hit a different rule:
     *   rudo     A-Level, strong results, Bulawayo, aged 19, wants Computer Science or Civil Engineering
     *   simba    A-Level, weak results, rural Manicaland, wants Agriculture
     *   nyasha   A-Level, top results in the sciences, Harare, wants Medicine or Nursing
     *   tawanda  Undergraduate degree finished (BSc Information Systems at MSU), Midlands, aged 24 - the next stage is Masters
     *   memory   Diploma holder (Diploma in Information Technology at HIT), O-Level results on file
     *   lloyd    Masters graduate (MSc Computer Science at UZ), aged 31
     *   anesu    O-Level, aged 16 (guardian on file), Masvingo, rural
     *   tariro   A-Level whose profile is deliberately thin: no province, no results - things ScholarFit must ASK about
     *   fadzai   A-Level, Midlands, rural, Gweru, applied to MSU
     *   joseph   A-Level, Mashonaland West, aged 17 (guardian on file), strong in Maths and Physics
     */
    private function applicants(): array
    {
        $a = [];

        $a['rudo'] = $this->applicant('fit.rudo.mutasa@scholarzim.co.zw', 'Rudo Mutasa', [
            'education_level' => EducationLevel::A_LEVEL, 'gender' => Gender::FEMALE, 'institution_name' => 'Mzilikazi High School',
            'province' => 'Bulawayo', 'locality' => 'Bulawayo', 'settlement_type' => SettlementType::URBAN, 'age' => 19,
            'biography' => 'Top of her class in Mathematics and Physics.', 'certificate' => 'results',
        ]);
        $this->results($a['rudo'], AcademicCatalogue::ZIMSEC_A_LEVEL, ['Mathematics' => 'A', 'Physics' => 'A', 'Chemistry' => 'B']);
        $this->intends($a['rudo'], ['BSc Computer Science', 'BEng Civil Engineering'], ['UZ']);

        $a['simba'] = $this->applicant('fit.simba.dube@scholarzim.co.zw', 'Simba Dube', [
            'education_level' => EducationLevel::A_LEVEL, 'gender' => Gender::MALE, 'institution_name' => 'Chimanimani High School',
            'province' => 'Manicaland', 'locality' => 'Chimanimani', 'settlement_type' => SettlementType::RURAL, 'age' => 20,
            'biography' => 'Rural learner who struggled with the sciences.', 'certificate' => 'results',
        ]);
        $this->results($a['simba'], AcademicCatalogue::ZIMSEC_A_LEVEL, ['Mathematics' => 'D', 'Physics' => 'E', 'Chemistry' => 'E']);
        $this->intends($a['simba'], ['BSc Agriculture'], []);

        $a['nyasha'] = $this->applicant('fit.nyasha.hove@scholarzim.co.zw', 'Nyasha Hove', [
            'education_level' => EducationLevel::A_LEVEL, 'gender' => Gender::FEMALE, 'institution_name' => 'Arundel School',
            'province' => 'Harare', 'locality' => 'Harare', 'settlement_type' => SettlementType::URBAN, 'age' => 18,
            'biography' => 'Wants to become a doctor.', 'certificate' => 'results',
        ]);
        $this->results($a['nyasha'], AcademicCatalogue::ZIMSEC_A_LEVEL, ['Biology' => 'A', 'Chemistry' => 'A', 'Mathematics' => 'A']);
        $this->intends($a['nyasha'], ['Bachelor of Medicine and Bachelor of Surgery', 'BSc Nursing Science'], ['UZ']);

        $a['tawanda'] = $this->applicant('fit.tawanda.zhou@scholarzim.co.zw', 'Tawanda Zhou', [
            'education_level' => EducationLevel::UNDERGRADUATE, 'gender' => Gender::MALE, 'institution_name' => 'Midlands State University',
            'province' => 'Midlands', 'locality' => 'Gweru', 'settlement_type' => SettlementType::URBAN, 'age' => 24,
            'field_of_study' => 'Information Systems', 'biography' => 'Third-year Information Systems student.', 'certificate' => 'transcript',
            'degree_classification' => 'Upper Second (2:1)',
        ]);
        $this->current($a['tawanda'], 'BSc Information Systems', 'MSU');

        $a['memory'] = $this->applicant('fit.memory.chikwanha@scholarzim.co.zw', 'Memory Chikwanha', [
            'education_level' => EducationLevel::DIPLOMA, 'gender' => Gender::FEMALE, 'institution_name' => 'Harare Institute of Technology',
            'province' => 'Mashonaland East', 'locality' => 'Marondera', 'settlement_type' => SettlementType::URBAN, 'age' => 27,
            'field_of_study' => 'Information Technology', 'biography' => 'Diploma holder returning to study.', 'certificate' => 'transcript',
        ]);
        // No degree classification: ScholarFit reads one as an Undergraduate degree held, which a Diploma is not.
        $this->results($a['memory'], AcademicCatalogue::ZIMSEC_O_LEVEL, ['Mathematics' => 'C', 'English Language' => 'B', 'Combined Science' => 'C', 'Geography' => 'C', 'Shona' => 'B']);
        $this->current($a['memory'], 'Diploma in Information Technology', 'HIT');

        $a['lloyd'] = $this->applicant('fit.lloyd.ncube@scholarzim.co.zw', 'Lloyd Ncube', [
            'education_level' => EducationLevel::MASTERS, 'gender' => Gender::MALE, 'institution_name' => 'University of Zimbabwe',
            'province' => 'Harare', 'locality' => 'Harare', 'settlement_type' => SettlementType::URBAN, 'age' => 31,
            'field_of_study' => 'Computer Science', 'biography' => 'MSc graduate looking at a doctorate.', 'certificate' => 'transcript',
            'degree_classification' => 'First Class',
        ]);
        $this->current($a['lloyd'], 'MSc Computer Science', 'UZ');

        $a['anesu'] = $this->applicant('fit.anesu.mlambo@scholarzim.co.zw', 'Anesu Mlambo', [
            'education_level' => EducationLevel::O_LEVEL, 'gender' => Gender::FEMALE, 'institution_name' => 'Masvingo Girls High School',
            'province' => 'Masvingo', 'locality' => 'Masvingo', 'settlement_type' => SettlementType::RURAL, 'age' => 16,
            'biography' => 'Finished O-Level, deciding on A-Level or a diploma.', 'certificate' => 'results', 'guardian' => 'Mrs Mlambo',
        ]);
        $this->results($a['anesu'], AcademicCatalogue::ZIMSEC_O_LEVEL, ['Mathematics' => 'B', 'English Language' => 'B', 'Combined Science' => 'B', 'Geography' => 'C', 'History' => 'C', 'Shona' => 'A']);
        $this->intends($a['anesu'], ['Diploma in Information Technology'], []);

        // Thin on purpose: no province, no date of birth, no results. A listing that needs any of them cannot be checked.
        $a['tariro'] = $this->applicant('fit.tariro.gumbo@scholarzim.co.zw', 'Tariro Gumbo', [
            'education_level' => EducationLevel::A_LEVEL, 'gender' => null, 'institution_name' => null,
            'province' => null, 'locality' => null, 'settlement_type' => null, 'age' => null,
            'biography' => null, 'certificate' => null,
        ]);

        $a['fadzai'] = $this->applicant('fit.fadzai.moyo@scholarzim.co.zw', 'Fadzai Moyo', [
            'education_level' => EducationLevel::A_LEVEL, 'gender' => Gender::FEMALE, 'institution_name' => 'Gweru Girls High School',
            'province' => 'Midlands', 'locality' => 'Gweru', 'settlement_type' => SettlementType::RURAL, 'age' => 19,
            'biography' => 'Rural learner with solid sciences.', 'certificate' => 'results',
        ]);
        $this->results($a['fadzai'], AcademicCatalogue::ZIMSEC_A_LEVEL, ['Mathematics' => 'B', 'Physics' => 'B', 'Chemistry' => 'C']);
        $this->intends($a['fadzai'], ['BSc Information Systems'], ['MSU']);

        $a['joseph'] = $this->applicant('fit.joseph.banda@scholarzim.co.zw', 'Joseph Banda', [
            'education_level' => EducationLevel::A_LEVEL, 'gender' => Gender::MALE, 'institution_name' => 'Chinhoyi High School',
            'province' => 'Mashonaland West', 'locality' => 'Chinhoyi', 'settlement_type' => SettlementType::URBAN, 'age' => 17,
            'biography' => 'Young A-Level leaver, strong in Maths and Physics.', 'certificate' => 'results', 'guardian' => 'Mr Banda',
        ]);
        $this->results($a['joseph'], AcademicCatalogue::ZIMSEC_A_LEVEL, ['Mathematics' => 'A', 'Physics' => 'B', 'Chemistry' => 'B']);
        $this->intends($a['joseph'], ['BEng Mining Engineering'], []);

        return $a;
    }

    /** @param array<string, mixed> $p */
    private function applicant(string $email, string $name, array $p): User
    {
        $user = User::updateOrCreate(['email' => $email], [
            'role_id' => Role::where('role_name', RoleNames::APPLICANT)->value('role_id'),
            'full_name' => $name,
            'phone' => '077' . random_int(1000000, 9999999),
            'password_hash' => Hash::make(self::PASSWORD),
            'account_status' => AccountStatus::ACTIVE,
            'email_verified' => true,
        ]);

        $attributes = [
            'education_level' => $p['education_level'],
            'gender' => $p['gender'] ?? null,
            'institution_name' => $p['institution_name'] ?? null,
            'country' => FormOptions::DEFAULT_COUNTRY,
            'province' => $p['province'] ?? null,
            'locality' => $p['locality'] ?? null,
            'settlement_type' => $p['settlement_type'] ?? null,
            'date_of_birth' => isset($p['age']) ? Carbon::today()->subYears($p['age'])->subDays(30)->toDateString() : null,
            'citizenship' => 'Zimbabwean',
            'field_of_study' => $p['field_of_study'] ?? null,
            'degree_classification' => $p['degree_classification'] ?? null,
            'biography' => $p['biography'] ?? null,
            'results_certificate_path' => null, 'results_certificate_filename' => null, 'results_uploaded_at' => null,
            'transcript_path' => null, 'transcript_filename' => null, 'transcript_uploaded_at' => null,
            'passport_path' => null, 'passport_filename' => null, 'passport_uploaded_at' => null,
            'recommendation_letter_path' => null, 'recommendation_letter_filename' => null, 'recommendation_letter_uploaded_at' => null,
            'guardian_name' => null, 'guardian_phone' => null, 'guardian_relationship' => null, 'guardian_confirmed_at' => null,
        ];

        if (($p['certificate'] ?? null) === 'results') {
            $attributes['results_certificate_path'] = 'profiles/demo/fit-results.pdf';
            $attributes['results_certificate_filename'] = 'results.pdf';
            $attributes['results_uploaded_at'] = Carbon::now()->subMonths(2);
        } elseif (($p['certificate'] ?? null) === 'transcript') {
            $attributes['transcript_path'] = 'profiles/demo/fit-transcript.pdf';
            $attributes['transcript_filename'] = 'transcript.pdf';
            $attributes['transcript_uploaded_at'] = Carbon::now()->subMonths(2);
            // Above school level an ID or passport is asked for as well.
            $attributes['passport_path'] = 'profiles/demo/fit-id.pdf';
            $attributes['passport_filename'] = 'id.pdf';
            $attributes['passport_uploaded_at'] = Carbon::now()->subMonths(2);
        }

        // Everyone who has an academic certificate on file also has the recommendation letter every level above Primary
        // is asked for, so these applicants are ready to apply as far as documents go. Tariro, who has none, shows the
        // apply page asking for what is missing.
        if (($p['certificate'] ?? null) !== null) {
            $attributes['recommendation_letter_path'] = 'profiles/demo/fit-recommendation.pdf';
            $attributes['recommendation_letter_filename'] = 'recommendation.pdf';
            $attributes['recommendation_letter_uploaded_at'] = Carbon::now()->subMonths(2);
        }

        if (isset($p['guardian'])) {
            $attributes['guardian_name'] = $p['guardian'];
            $attributes['guardian_phone'] = '0773000111';
            $attributes['guardian_relationship'] = 'Parent';
            $attributes['guardian_confirmed_at'] = Carbon::now()->subWeek();
        }

        $profile = ApplicantProfile::updateOrCreate(['user_id' => $user->user_id], $attributes);

        // Reset what this seeder owns, so a second run leaves exactly what is written here.
        AcademicResult::where('profile_id', $profile->profile_id)->delete();
        ApplicantProgramme::where('profile_id', $profile->profile_id)->delete();
        ApplicantInstitution::where('profile_id', $profile->profile_id)->delete();

        return $user;
    }

    private function profileOf(User $user): ApplicantProfile
    {
        return ApplicantProfile::where('user_id', $user->user_id)->firstOrFail();
    }

    /** @param array<string, string> $grades subject name => grade symbol */
    private function results(User $user, string $qualificationKey, array $grades): void
    {
        $profile = $this->profileOf($user);
        $qualification = AcademicQualification::findByKey($qualificationKey);

        foreach ($grades as $subjectName => $grade) {
            $subject = $qualification?->subjects()->where('name', $subjectName)->first();

            if ($subject === null) {
                continue;
            }

            AcademicResult::create([
                'profile_id' => $profile->profile_id, 'qualification_id' => $qualification->id, 'subject_id' => $subject->id,
                'result' => $grade, 'year' => 2025, 'derived_points' => $qualification->pointsFor($grade),
            ]);
        }
    }

    /** What a school-leaver hopes to study, and where they have applied. @param array<int, string> $programmes @param array<int, string> $institutionCodes */
    private function intends(User $user, array $programmes, array $institutionCodes): void
    {
        $profile = $this->profileOf($user);

        foreach ($programmes as $name) {
            if ($programme = Programme::where('name', $name)->first()) {
                ApplicantProgramme::create(['profile_id' => $profile->profile_id, 'programme_id' => $programme->id, 'kind' => ApplicantProgramme::INTENDED]);
            }
        }

        foreach ($institutionCodes as $code) {
            if ($institution = Institution::where('code', $code)->first()) {
                ApplicantInstitution::create(['profile_id' => $profile->profile_id, 'institution_id' => $institution->id]);
            }
        }
    }

    /** The programme an enrolled or graduated student is on, and where. */
    private function current(User $user, string $programmeName, string $institutionCode): void
    {
        $programme = Programme::where('name', $programmeName)->first();

        if ($programme === null) {
            return;
        }

        ApplicantProgramme::create([
            'profile_id' => $this->profileOf($user)->profile_id, 'programme_id' => $programme->id, 'kind' => ApplicantProgramme::CURRENT,
            'institution_id' => Institution::where('code', $institutionCode)->value('id'),
        ]);
    }

    // ------------------------------------------------------------- listings --

    /**
     * Each listing says what it tests. "open to" is always answered, as the form now requires.
     *
     * @param  array<string, User>  $providers
     */
    private function listings(array $providers): void
    {
        $health = Field::where('code', '09')->value('id');
        $programmes = fn (array $names) => Programme::whereIn('name', $names)->pluck('id')->all();
        $institution = fn (string $code) => Institution::where('code', $code)->value('id');

        // Tests: nothing stated at all. Open to every student, whatever their profile holds.
        $this->listing($providers['open'], 'FIT Open Doors Bursary', null, null, ['open_to_all_programmes' => true, 'any_institution' => true]);

        // Tests: points floor (12) plus a Mathematics grade. Rudo and Nyasha meet it, Simba and Fadzai do not; Tariro has no results to check.
        $stem = $this->listing($providers['stem'], 'FIT STEM Excellence Award', EducationLevel::UNDERGRADUATE, null, ['min_academic_points' => 12, 'open_to_all_programmes' => true, 'any_institution' => true]);
        $this->subjectRequirement($stem, 'Mathematics', 'B');

        // Tests: programme scope. Open to three Computing programmes only. Rudo (intends Computer Science), Fadzai (Information Systems) match; Nyasha, Simba do not.
        $this->listing($providers['stem'], 'FIT Computing Programmes Bursary', EducationLevel::UNDERGRADUATE, null, ['open_to_all_programmes' => false, 'any_institution' => true],
            programmeIds: $programmes(['BSc Computer Science', 'BSc Information Systems', 'BSc Information Technology']));

        // Tests: field scope (Health and welfare). Nyasha (Medicine, Nursing) matches; Rudo, Joseph do not.
        $this->listing($providers['stem'], 'FIT Health Sciences Scholarship', EducationLevel::UNDERGRADUATE, null, ['open_to_all_programmes' => false, 'any_institution' => true],
            fieldIds: array_filter([$health]));

        // Tests: institution scope. Only students at or applying to MSU. Fadzai (applied to MSU) matches; Rudo (applied to UZ) does not.
        $this->listing($providers['regional'], 'FIT Midlands State University Bursary', EducationLevel::UNDERGRADUATE, null, ['open_to_all_programmes' => true, 'any_institution' => false],
            institutionIds: array_filter([$institution('MSU')]));

        // Tests: province and settlement type together. Manicaland and rural: Simba qualifies on residence (but not on points elsewhere); Fadzai (Midlands) does not; Tariro has no province recorded.
        $this->listing($providers['regional'], 'FIT Manicaland Rural Learners Fund', EducationLevel::UNDERGRADUATE, null,
            ['required_province' => 'Manicaland', 'target_settlement_type' => SettlementType::RURAL, 'open_to_all_programmes' => true, 'any_institution' => true]);

        // Tests: locality. Gweru residents: Fadzai and Tawanda; nobody else.
        $this->listing($providers['regional'], 'FIT Gweru Community Scholarship', EducationLevel::UNDERGRADUATE, null, ['target_locality' => 'Gweru', 'open_to_all_programmes' => true, 'any_institution' => true]);

        // Tests: age limit (22 and under). Rudo (19) and Nyasha (18) pass; Tawanda (24) fails.
        $this->listing($providers['stem'], 'FIT Young Innovators Under-22 Grant', EducationLevel::UNDERGRADUATE, null, ['max_age' => 22, 'open_to_all_programmes' => true, 'any_institution' => true]);

        // Tests: age limit at Masters (30 and under) and the step up. Lloyd (31) fails on age.
        $this->listing($providers['stem'], 'FIT Postgraduate Computing Fellowship', EducationLevel::MASTERS, null, ['max_age' => 30, 'open_to_all_programmes' => true, 'any_institution' => true]);

        // Tests: the education pathway. A Diploma award for school-leavers: Anesu (O-Level) can, Memory (already a Diploma holder) is not at the right step.
        $this->listing($providers['open'], 'FIT Diploma Skills Award', EducationLevel::DIPLOMA, null, ['open_to_all_programmes' => true, 'any_institution' => true]);

        // Tests: proof of results on file. Tariro has none, so ScholarFit must ASK, not refuse. Everyone else has a certificate or transcript.
        $this->listing($providers['stem'], 'FIT Proof Of Results Scholarship', EducationLevel::UNDERGRADUATE, null, ['requires_results_certificate' => true, 'open_to_all_programmes' => true, 'any_institution' => true]);

        // Tests: an explicit entry floor. Undergraduate award that needs at least a Diploma first: Memory has one, school-leavers do not.
        $this->listing($providers['open'], 'FIT Bachelor Completion Bursary', EducationLevel::UNDERGRADUATE, null,
            ['minimum_education_level' => EducationLevel::DIPLOMA, 'open_to_all_programmes' => true, 'any_institution' => true]);

        // Tests: the top of the ladder. A PhD award: Lloyd (finished Masters) is at the right step, Tawanda (finished a degree) is two steps short.
        $this->listing($providers['stem'], 'FIT Doctoral Research Grant', EducationLevel::PHD, null, ['open_to_all_programmes' => true, 'any_institution' => true]);

        // Tests: a listing past its deadline never appears for anybody.
        $this->listing($providers['open'], 'FIT Expired Merit Award', null, null, ['open_to_all_programmes' => true, 'any_institution' => true], deadline: Carbon::today()->subDays(3));

        // Tests: still waiting for the moderator, so not public and not matched.
        $this->listing($providers['open'], 'FIT Awaiting Review Award', null, null, ['open_to_all_programmes' => true, 'any_institution' => true], moderation: OpportunityModerationStatus::PENDING);
    }

    /**
     * @param  array<string, mixed>  $extra
     * @param  array<int, int>  $programmeIds
     * @param  array<int, int>  $fieldIds
     * @param  array<int, int>  $institutionIds
     */
    private function listing(
        User $provider,
        string $title,
        ?string $level,
        ?string $field,
        array $extra = [],
        array $programmeIds = [],
        array $fieldIds = [],
        array $institutionIds = [],
        ?Carbon $deadline = null,
        string $moderation = OpportunityModerationStatus::APPROVED,
    ): Opportunity {
        $reset = [
            'minimum_education_level' => null, 'min_academic_points' => null, 'max_age' => null, 'required_province' => null,
            'target_locality' => null, 'target_settlement_type' => null, 'requires_results_certificate' => false,
        ];

        $listing = Opportunity::updateOrCreate(['title' => $title], array_merge($reset, [
            'provider_user_id' => $provider->user_id,
            'provider_name' => $provider->full_name,
            'description' => 'A ScholarFit test listing. ' . $title . ' covers tuition and a study allowance.',
            'education_level' => $level,
            'target_field' => $field,
            'funding_type' => 'Full Scholarship',
            'country' => FormOptions::DEFAULT_COUNTRY,
            'target_country' => FormOptions::DEFAULT_COUNTRY,
            'deadline' => ($deadline ?? Carbon::today()->addDays(60))->toDateString(),
            'status' => OpportunityStatus::ACTIVE,
            'moderation_status' => $moderation,
            'submitted_at' => Carbon::now()->subDays(10),
            'reviewed_at' => $moderation === OpportunityModerationStatus::APPROVED ? Carbon::now()->subDays(9) : null,
            'reviewed_by' => $moderation === OpportunityModerationStatus::APPROVED ? config('scholarzim.admin.email') : null,
            'created_at' => Carbon::now()->subDays(10),
        ], $extra));

        $listing->scopes()->delete();
        $listing->subjectRequirements()->delete();

        foreach ($programmeIds as $id) {
            OpportunityScope::create(['opportunity_id' => $listing->opportunity_id, 'programme_id' => $id]);
        }
        foreach ($fieldIds as $id) {
            OpportunityScope::create(['opportunity_id' => $listing->opportunity_id, 'field_id' => $id]);
        }
        foreach ($institutionIds as $id) {
            OpportunityScope::create(['opportunity_id' => $listing->opportunity_id, 'institution_id' => $id]);
        }

        return $listing;
    }

    private function subjectRequirement(Opportunity $listing, string $subjectName, string $grade): void
    {
        $qualification = AcademicQualification::findByKey(AcademicCatalogue::ZIMSEC_A_LEVEL);
        $subject = AcademicSubject::where('qualification_id', $qualification->id)->where('name', $subjectName)->first();

        if ($subject !== null) {
            OpportunitySubjectRequirement::create([
                'opportunity_id' => $listing->opportunity_id, 'qualification_id' => $qualification->id,
                'subject_id' => $subject->id, 'minimum_grade' => $grade,
            ]);
        }
    }

    // --------------------------------------------------------------- report --

    /** @param array<int, User> $applicants */
    private function report(array $applicants): void
    {
        if ($this->command === null) {
            return;
        }

        $evaluator = app(EligibilityEvaluator::class);
        $listings = Opportunity::where('title', 'like', 'FIT %')->orderBy('opportunity_id')->get();
        $rows = [];

        foreach ($listings as $listing) {
            $row = [$listing->title . ($listing->isPubliclyVisible() ? '' : '  [not public]')];

            foreach ($applicants as $user) {
                $profile = $this->profileOf($user)->load(['academicResults.qualification', 'academicResults.subject.qualification', 'programmeChoices.programme.field.parent', 'programmeChoices.institution', 'appliedInstitutions.institution', 'targetFields.field.parent']);
                $listing->load(['subjectRequirements', 'subjectRequirements.subject.qualification', 'subjectRequirements.qualification', 'scopes.programme.field.parent', 'scopes.field.parent', 'scopes.institution']);
                $fit = new EligibilityResult($listing, $evaluator->evaluate($profile, $listing, AcademicRecord::fromProfile($profile)), $profile->isComplete());

                $row[] = match (true) {
                    ! $listing->isPubliclyVisible() => '-',
                    $fit->isIneligible() => 'NO',
                    $fit->needsInformation() => '?',
                    ! $fit->hasStatedRequirements() => 'open',
                    default => 'YES',
                };
            }

            $rows[] = $row;
        }

        $this->command->newLine();
        $this->command->line('YES eligible, NO refused for a stated reason, ? needs information from the profile, open = no requirements stated (eligible to apply), - not public.');
        $this->command->table(array_merge(['Listing'], array_map(fn (User $u) => explode(' ', $u->full_name)[0], $applicants)), $rows);
        $this->command->line('Log in as any applicant (fit.<first>.<last>@scholarzim.co.zw) and open /applicant/recommendations to compare with the table.');
    }
}
