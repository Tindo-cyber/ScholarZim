<?php

namespace Database\Seeders;

use App\Models\ApplicantProfile;
use App\Models\Application;
use App\Models\AuditLog;
use App\Models\Notification;
use App\Models\Opportunity;
use App\Models\ProviderProfile;
use App\Models\Role;
use App\Models\User;
use App\Support\AccountStatus;
use App\Support\ApplicationStatus;
use App\Support\AuditAction;
use App\Support\EducationLevel;
use App\Support\FormOptions;
use App\Support\NotificationType;
use App\Support\OpportunityModerationStatus;
use App\Support\OpportunityStatus;
use App\Support\ProviderOrgType;
use App\Support\RoleNames;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class DatabaseSeeder extends Seeder
{
    /**
     * Demo fixtures for a local database: three accounts, six applicants across
     * the education pathway spectrum, a few listings, and applications in three
     * statuses. Everything here is deliberately reproducible, which is also why
     * it must never touch a live one - the accounts carry a password that is
     * written down in the README, and updateOrCreate means a second run does not
     * skip them, it resets them back to it.
     *
     * The refusal below is the innermost of three. docker/entrypoint.sh will not
     * call this under APP_ENV=production, and render.yaml ships
     * SCHOLARZIM_DEMO_SEED=false; this one catches the case neither can see,
     * which is a person typing `php artisan db:seed` against production.
     *
     * It does not gate anything a deployment needs. The three role rows are
     * inserted by the 2024_01_01_000001 migration, so a fresh production
     * database is complete after `migrate` alone - ensureRoles() below is a
     * local convenience, not the source of truth.
     */
    public function run(): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException(
                'DatabaseSeeder creates demo accounts with a published password and will not run in production. '
                . 'Roles are created by migrations, so nothing here is required for a deployment.'
            );
        }

        $this->ensureRoles();

        $admin = $this->admin();
        $provider = $this->provider();
        $pendingProvider = $this->pendingProvider();

        // Six applicants across the education pathway spectrum, so a viva can
        // walk every rule in App\Services\ScholarFit\EducationPathway against a
        // real profile instead of a constructed one:
        //   Tendai       Undergraduate, transcript on file
        //   Chipo        A-Level, profile deliberately incomplete
        //   Kudzai       Primary, guardian-assisted Form 1 pathway
        //   Farai        O-Level, results certificate on file
        //   Tanaka       A-Level, results certificate on file
        //   Blessing     Masters, transcript on file
        $applicant = $this->applicant();
        $incompleteApplicant = $this->applicantWithoutCertificate();
        $primaryApplicant = $this->primaryApplicantWithGuardian();
        $oLevelApplicant = $this->oLevelApplicant();
        $aLevelApplicant = $this->aLevelApplicant();
        $mastersApplicant = $this->mastersApplicant();

        $this->opportunities($provider);
        $this->applications($applicant, $incompleteApplicant);
        $this->auditTrail($admin, $provider, $pendingProvider);

        $this->command->info(
            'Seeded admin, two providers (one pending), six applicants spanning Primary through Masters '
            . '(one incomplete), demo listings, applications in three statuses, notifications, and audit history.'
        );
    }

    private function ensureRoles(): void
    {
        foreach ([
            [RoleNames::APPLICANT, 'Scholarship applicant'],
            [RoleNames::PROVIDER, 'Scholarship provider'],
            [RoleNames::ADMIN, 'Platform administrator'],
        ] as [$name, $description]) {
            Role::updateOrCreate(['role_name' => $name], ['description' => $description]);
        }
    }

    private function admin(): User
    {
        return User::updateOrCreate(
            ['email' => config('scholarzim.admin.email')],
            [
                'role_id' => Role::where('role_name', RoleNames::ADMIN)->value('role_id'),
                'full_name' => 'Platform Administrator',
                'password_hash' => Hash::make(config('scholarzim.admin.password')),
                'account_status' => AccountStatus::ACTIVE,
                'email_verified' => true,
                'is_super_admin' => true,
            ]
        );
    }

    private function provider(): User
    {
        $provider = User::updateOrCreate(
            ['email' => 'provider@scholarzim.co.zw'],
            [
                'role_id' => Role::where('role_name', RoleNames::PROVIDER)->value('role_id'),
                'full_name' => 'Zimbabwe Education Trust',
                'phone' => '+263 242 700 000',
                'password_hash' => Hash::make('ChangeMe123'),
                'account_status' => AccountStatus::ACTIVE,
                'email_verified' => true,
            ]
        );

        ProviderProfile::updateOrCreate(
            ['user_id' => $provider->user_id],
            [
                'organisation_type' => ProviderOrgType::FOUNDATION,
                'registration_number' => 'PVO 12/2011',
                'certificate_path' => 'provider-certificates/demo-certificate.pdf',
                'certificate_filename' => 'registration-certificate.pdf',
                'submitted_at' => Carbon::now()->subMonths(6),
                'reviewed_at' => Carbon::now()->subMonths(6)->addDay(),
                'reviewed_by' => config('scholarzim.admin.email'),
            ]
        );

        return $provider;
    }

    /**
     * A second provider, awaiting verification. Without this the admin
     * verification screen - one of the flows a viva walks through live - has
     * nothing in its queue on first login.
     */
    private function pendingProvider(): User
    {
        $provider = User::updateOrCreate(
            ['email' => 'trust@scholarzim.co.zw'],
            [
                'role_id' => Role::where('role_name', RoleNames::PROVIDER)->value('role_id'),
                'full_name' => 'Midlands Community Development Trust',
                'phone' => '+263 771 900 000',
                'password_hash' => Hash::make('ChangeMe123'),
                'account_status' => AccountStatus::PENDING,
                'email_verified' => true,
            ]
        );

        // Mirrors exactly what registration leaves behind: a certificate and
        // organisation details, submitted, and nothing reviewed yet - the same
        // shape ProviderVerificationTest asserts against a real registration.
        ProviderProfile::updateOrCreate(
            ['user_id' => $provider->user_id],
            [
                'organisation_type' => ProviderOrgType::NGO,
                'registration_number' => 'PVO 44/2025',
                'certificate_path' => 'provider-certificates/demo-pending-certificate.pdf',
                'certificate_filename' => 'registration-certificate.pdf',
                'submitted_at' => Carbon::now()->subDays(2),
                'reviewed_at' => null,
                'reviewed_by' => null,
                'rejection_reason' => null,
            ]
        );

        return $provider;
    }

    /**
     * The primary demo student: Undergraduate, with the full document set a
     * tertiary applicant is actually asked for - a transcript, not a results
     * certificate, which is the O/A-Level document. See
     * ApplicantProfile::hasRequiredAcademicEvidence().
     */
    private function applicant(): User
    {
        $applicant = User::updateOrCreate(
            ['email' => 'student@scholarzim.co.zw'],
            [
                'role_id' => Role::where('role_name', RoleNames::APPLICANT)->value('role_id'),
                'full_name' => 'Tendai Moyo',
                'phone' => '+263 771 000 000',
                'password_hash' => Hash::make('ChangeMe123'),
                'account_status' => AccountStatus::ACTIVE,
                'email_verified' => true,
            ]
        );

        ApplicantProfile::updateOrCreate(
            ['user_id' => $applicant->user_id],
            [
                'education_level' => EducationLevel::UNDERGRADUATE,
                'institution_name' => 'University of Zimbabwe (UZ)',
                'field_of_study' => 'Computer Science & IT',
                'year_of_study' => 2,
                'country' => FormOptions::DEFAULT_COUNTRY,
                'province' => 'Harare',
                'date_of_birth' => Carbon::today()->subYears(21)->toDateString(),
                'citizenship' => 'Zimbabwean',
                'academic_results' => 'Upper second class standing after year one',
                'biography' => 'Second-year computing student building civic-tech projects for rural schools.',
                // Deliberately no results_certificate_path: Tendai is
                // Undergraduate, so his academic evidence is a transcript, not
                // an O/A-Level results certificate.
                'transcript_path' => 'profiles/demo/transcript.pdf',
                'transcript_filename' => 'uz-transcript.pdf',
                'transcript_uploaded_at' => Carbon::now()->subMonth(),
                // The demo student carries the full document set, so the apply
                // wizard can be walked end to end in a viva without stopping to
                // upload four files.
                'cv_path' => 'profiles/demo/cv.pdf',
                'cv_filename' => 'tendai-moyo-cv.pdf',
                'cv_uploaded_at' => Carbon::now()->subMonth(),
                'passport_path' => 'profiles/demo/id.pdf',
                'passport_filename' => 'national-id.pdf',
                'passport_uploaded_at' => Carbon::now()->subMonth(),
                'recommendation_letter_path' => 'profiles/demo/recommendation.pdf',
                'recommendation_letter_filename' => 'lecturer-recommendation.pdf',
                'recommendation_letter_uploaded_at' => Carbon::now()->subMonth(),
            ]
        );

        return $applicant;
    }

    /**
     * A second applicant whose profile is deliberately not finished: no results
     * certificate, no CV, no recommendation letter. `isComplete()` reads false
     * for this account and the profile checklist has open items - the state
     * Phase 13 of a viva needs to show "what an unfinished profile looks like"
     * without narrating it from an empty database.
     *
     * The certificate is advisory, not enforced by ScholarFit's score: nothing
     * in ScholarFit blocks a *recommendation* on it. Actually applying is a
     * different matter - the "Harare Health Sciences Postgraduate Grant"
     * listing sets requires_results_certificate, which is a hard, applying-time
     * requirement, and this account cannot pass it, by design. See
     * docs/user-guide.md.
     */
    private function applicantWithoutCertificate(): User
    {
        $applicant = User::updateOrCreate(
            ['email' => 'chipo.ncube@scholarzim.co.zw'],
            [
                'role_id' => Role::where('role_name', RoleNames::APPLICANT)->value('role_id'),
                'full_name' => 'Chipo Ncube',
                'phone' => '+263 772 000 000',
                'password_hash' => Hash::make('ChangeMe123'),
                'account_status' => AccountStatus::ACTIVE,
                'email_verified' => true,
            ]
        );

        ApplicantProfile::updateOrCreate(
            ['user_id' => $applicant->user_id],
            [
                'education_level' => EducationLevel::A_LEVEL,
                'institution_name' => 'Mutare Girls High School',
                'country' => FormOptions::DEFAULT_COUNTRY,
                'province' => 'Manicaland',
                'date_of_birth' => Carbon::today()->subYears(18)->toDateString(),
                'citizenship' => 'Zimbabwean',
                'academic_results' => '13 points at A-Level (Biology A, Chemistry B, Maths B)',
                'biography' => 'Aspiring doctor looking for support through the first year of medical school.',
                // Deliberately absent: results_certificate_path, cv_path,
                // passport_path, recommendation_letter_path. That is the point
                // of this account. field_of_study is also absent, which is
                // correct rather than incomplete - it does not apply at A-Level.
            ]
        );

        return $applicant;
    }

    /**
     * A Primary pupil, whose only route onto the platform is the
     * guardian-assisted Form 1 pathway - see EducationLevel::isPrimary() and
     * ApplicantProfileService::update(). No document is required to start it,
     * and no field of study or academic-results box is shown to this account at
     * all: both are tertiary-and-above concepts.
     */
    private function primaryApplicantWithGuardian(): User
    {
        $applicant = User::updateOrCreate(
            ['email' => 'kudzai.marufu@scholarzim.co.zw'],
            [
                'role_id' => Role::where('role_name', RoleNames::APPLICANT)->value('role_id'),
                'full_name' => 'Kudzai Marufu',
                'phone' => '+263 773 111 000',
                'password_hash' => Hash::make('ChangeMe123'),
                'account_status' => AccountStatus::ACTIVE,
                'email_verified' => true,
            ]
        );

        ApplicantProfile::updateOrCreate(
            ['user_id' => $applicant->user_id],
            [
                'education_level' => EducationLevel::PRIMARY,
                'institution_name' => 'Chinhoyi Primary School',
                'country' => FormOptions::DEFAULT_COUNTRY,
                'province' => 'Mashonaland West',
                'date_of_birth' => Carbon::today()->subYears(12)->toDateString(),
                'citizenship' => 'Zimbabwean',
                'guardian_name' => 'Grace Marufu',
                'guardian_phone' => '+263 773 111 001',
                'guardian_relationship' => 'Mother',
                'guardian_confirmed_at' => Carbon::now()->subWeek(),
                'biography' => 'Grade 7 pupil sitting the transition to Form 1 next year.',
            ]
        );

        return $applicant;
    }

    /**
     * An O-Level applicant with a results certificate on file - the demo pair
     * for both "O-Level can reach A-Level" and "O-Level can reach Undergraduate
     * when the specific listing allows it" (EducationPathway, then
     * Opportunity::minimum_education_level). Manicaland on purpose, so she also
     * clears the province rule the same way Chipo does.
     */
    private function oLevelApplicant(): User
    {
        $applicant = User::updateOrCreate(
            ['email' => 'farai.sibanda@scholarzim.co.zw'],
            [
                'role_id' => Role::where('role_name', RoleNames::APPLICANT)->value('role_id'),
                'full_name' => 'Farai Sibanda',
                'phone' => '+263 774 222 000',
                'password_hash' => Hash::make('ChangeMe123'),
                'account_status' => AccountStatus::ACTIVE,
                'email_verified' => true,
            ]
        );

        ApplicantProfile::updateOrCreate(
            ['user_id' => $applicant->user_id],
            [
                'education_level' => EducationLevel::O_LEVEL,
                'institution_name' => 'Mutare Boys High School',
                'country' => FormOptions::DEFAULT_COUNTRY,
                'province' => 'Manicaland',
                'locality' => 'Mutare',
                'settlement_type' => \App\Services\ScholarFit\Taxonomy\SettlementType::URBAN,
                'date_of_birth' => Carbon::today()->subYears(17)->toDateString(),
                'citizenship' => 'Zimbabwean',
                'academic_results' => '7 O-Level passes including Maths and English',
                'biography' => 'Finished O-Level and weighing A-Level against a direct move to a diploma programme.',
                'results_certificate_path' => 'profiles/demo/o-level-results.pdf',
                'results_certificate_filename' => 'farai-o-level-results.pdf',
                'results_uploaded_at' => Carbon::now()->subMonths(2),
            ]
        );

        return $applicant;
    }

    /**
     * An A-Level applicant with a results certificate on file - the demo pair
     * for "A-Level can reach Undergraduate", including a listing that states an
     * explicit minimum_education_level of A-Level, which she exactly meets.
     */
    private function aLevelApplicant(): User
    {
        $applicant = User::updateOrCreate(
            ['email' => 'tanaka.chirwa@scholarzim.co.zw'],
            [
                'role_id' => Role::where('role_name', RoleNames::APPLICANT)->value('role_id'),
                'full_name' => 'Tanaka Chirwa',
                'phone' => '+263 775 333 000',
                'password_hash' => Hash::make('ChangeMe123'),
                'account_status' => AccountStatus::ACTIVE,
                'email_verified' => true,
            ]
        );

        ApplicantProfile::updateOrCreate(
            ['user_id' => $applicant->user_id],
            [
                'education_level' => EducationLevel::A_LEVEL,
                'institution_name' => 'Prince Edward School',
                'country' => FormOptions::DEFAULT_COUNTRY,
                'province' => 'Harare',
                'date_of_birth' => Carbon::today()->subYears(19)->toDateString(),
                'citizenship' => 'Zimbabwean',
                'academic_results' => '15 points at A-Level (Maths A, Physics A, Chemistry B)',
                'biography' => 'A-Level leaver applying straight into an engineering degree.',
                'results_certificate_path' => 'profiles/demo/a-level-results.pdf',
                'results_certificate_filename' => 'tanaka-a-level-results.pdf',
                'results_uploaded_at' => Carbon::now()->subMonths(3),
            ]
        );

        return $applicant;
    }

    /**
     * A Masters applicant with a transcript on file - postgraduate academic
     * evidence is a transcript or qualification record, never GPA and never an
     * O/A-Level certificate. See ApplicantProfile::hasRequiredAcademicEvidence().
     */
    private function mastersApplicant(): User
    {
        $applicant = User::updateOrCreate(
            ['email' => 'blessing.moyana@scholarzim.co.zw'],
            [
                'role_id' => Role::where('role_name', RoleNames::APPLICANT)->value('role_id'),
                'full_name' => 'Blessing Moyana',
                'phone' => '+263 776 444 000',
                'password_hash' => Hash::make('ChangeMe123'),
                'account_status' => AccountStatus::ACTIVE,
                'email_verified' => true,
            ]
        );

        ApplicantProfile::updateOrCreate(
            ['user_id' => $applicant->user_id],
            [
                'education_level' => EducationLevel::MASTERS,
                'institution_name' => 'University of Zimbabwe (UZ)',
                'field_of_study' => 'Medicine & Health Sciences',
                'year_of_study' => 1,
                'country' => FormOptions::DEFAULT_COUNTRY,
                'province' => 'Harare',
                'date_of_birth' => Carbon::today()->subYears(27)->toDateString(),
                'citizenship' => 'Zimbabwean',
                'academic_results' => 'Distinction, BSc Nursing Science',
                'biography' => 'Registered nurse pursuing a Masters in public health.',
                'transcript_path' => 'profiles/demo/masters-transcript.pdf',
                'transcript_filename' => 'blessing-masters-transcript.pdf',
                'transcript_uploaded_at' => Carbon::now()->subMonths(1),
            ]
        );

        return $applicant;
    }

    private function opportunities(User $provider): void
    {
        $listings = [
            ['Zimbabwe Tech Futures Undergraduate Bursary', 'Computer Science & IT', EducationLevel::UNDERGRADUATE, 'Full Scholarship', 45],
            ['Midlands Engineering Excellence Award', 'Engineering', EducationLevel::UNDERGRADUATE, 'Tuition Only', 12],
            ['Harare Health Sciences Postgraduate Grant', 'Medicine & Health Sciences', EducationLevel::MASTERS, 'Tuition + Accommodation', 90],
            ['Rural Schools A-Level Support Fund', 'General Secondary', EducationLevel::A_LEVEL, 'Partial Scholarship', 30],
            ['Agribusiness Innovation Research Grant', 'Agriculture & Agribusiness', EducationLevel::PHD, 'Research Grant', 120],
            // Form 1 is a target-only level: a transition award for a Primary
            // pupil, never a level any applicant's own profile is set to. See
            // EducationLevel's class docblock.
            ['Chinhoyi Form 1 Transition Bursary', 'General Secondary', EducationLevel::FORM_1, 'Full Scholarship', 60],
        ];

        foreach ($listings as [$title, $field, $level, $funding, $daysOut]) {
            Opportunity::updateOrCreate(
                ['title' => $title],
                [
                    'provider_user_id' => $provider->user_id,
                    'provider_name' => $provider->full_name,
                    'description' => 'This award covers tuition and a study allowance for the full duration of the '
                        . 'programme. Applicants must be Zimbabwean citizens in financial need with a strong '
                        . 'academic record. Applications are reviewed by the provider, who accepts or declines each one with a reason.',
                    'education_level' => $level,
                    'target_field' => $field,
                    'funding_type' => $funding,
                    'country' => FormOptions::DEFAULT_COUNTRY,
                    'target_country' => FormOptions::DEFAULT_COUNTRY,
                    'deadline' => Carbon::today()->addDays($daysOut),
                    'status' => OpportunityStatus::ACTIVE,
                    'moderation_status' => OpportunityModerationStatus::APPROVED,
                    'submitted_at' => Carbon::now()->subDays(20),
                    'reviewed_at' => Carbon::now()->subDays(19),
                    'reviewed_by' => config('scholarzim.admin.email'),
                    'created_at' => Carbon::now()->subDays(20),
                ]
            );
        }

        // A genuine hard requirement on one listing, so a viva can show "Requirements
        // not met" - no percentage, an explicit reason - rather than only ever
        // demonstrating a weighted score. Restricted to Manicaland: Chipo and Farai
        // (seeded there) meet it, Tendai (Harare) does not, which is the pairing
        // that makes the difference visible without narrating it.
        Opportunity::where('title', 'Rural Schools A-Level Support Fund')
            ->update(['required_province' => 'Manicaland']);

        // A second, distinct hard rule: a listing that requires proof of academic
        // results on file. Chipo (seeded without one) shows "Requirements not
        // met" here specifically because of the missing document, which is the
        // one demonstration that ties her incomplete profile to a visible
        // consequence rather than only a checklist badge. Blessing has her
        // transcript, so her score for this listing is unaffected.
        Opportunity::where('title', 'Harare Health Sciences Postgraduate Grant')
            ->update(['requires_results_certificate' => true]);

        // An explicit provider-stated floor, narrower than the general pathway:
        // this listing targets Undergraduate but will not take an O-Level
        // applicant directly, only A-Level and above. Tanaka (A-Level) exactly
        // meets it; Farai (O-Level) does not. The general pathway from O-Level
        // to Undergraduate is also blocked - see EducationPathway - so this
        // listing is unreachable for an O-Level applicant either way.
        Opportunity::where('title', 'Midlands Engineering Excellence Award')
            ->update(['minimum_education_level' => EducationLevel::A_LEVEL]);

        // One listing left in the queue so the admin moderation panel has content.
        Opportunity::updateOrCreate(
            ['title' => 'Bulawayo Mining Skills Scholarship'],
            [
                'provider_user_id' => $provider->user_id,
                'provider_name' => $provider->full_name,
                'description' => 'A new award for mining and metallurgy students, awaiting administrator review.',
                'education_level' => EducationLevel::DIPLOMA,
                'target_field' => 'Mining & Metallurgy',
                'funding_type' => 'Partial Scholarship',
                'country' => FormOptions::DEFAULT_COUNTRY,
                'target_country' => FormOptions::DEFAULT_COUNTRY,
                'deadline' => Carbon::today()->addDays(60),
                'status' => OpportunityStatus::ACTIVE,
                'moderation_status' => OpportunityModerationStatus::PENDING,
                'submitted_at' => Carbon::now()->subDays(2),
                'created_at' => Carbon::now()->subDays(2),
            ]
        );
    }

    /**
     * Applications in three statuses, split across the two applicants
     * deliberately rather than stacked on one.
     *
     * The first pass put PENDING, ACCEPTED and REJECTED all on the primary
     * demo student, against "Zimbabwe Tech Futures Undergraduate Bursary" and
     * "Harare Health Sciences Postgraduate Grant" - which turned out to be the
     * two listings eleven other test files already use as "the seeded
     * student's next free listing to apply to". Every one of them broke on the
     * unique (user, opportunity) constraint, because that student was no
     * longer free to apply there. Recommendation tests broke too, for a
     * quieter reason: three applications plus the new hard-requirement rule
     * below between them removed all but one of his six listings from his own
     * recommendable pool.
     *
     * So the primary student keeps exactly what he always had - one PENDING
     * application on "Midlands Engineering Excellence Award" - and is free to
     * apply to anything else live during a demo. The decided applications
     * (ACCEPTED, REJECTED) belong to the second applicant instead, on two
     * listings nothing else in the test suite references by name.
     */
    private function applications(User $applicant, User $incompleteApplicant): void
    {
        $pending = Opportunity::where('title', 'Midlands Engineering Excellence Award')->first();

        if ($pending) {
            Application::updateOrCreate(
                ['user_id' => $applicant->user_id, 'opportunity_id' => $pending->opportunity_id],
                [
                    'application_status' => ApplicationStatus::PENDING,
                    'submitted_at' => Carbon::now()->subDays(5),
                    'decision_reason' => null,
                    'decided_at' => null,
                    'personal_statement' => 'I am applying because this award would let me finish my degree '
                        . 'without interrupting my studies to work. I intend to build software for Zimbabwean schools.',
                ]
            );
        }

        $accepted = Opportunity::where('title', 'Rural Schools A-Level Support Fund')->first();
        $rejected = Opportunity::where('title', 'Agribusiness Innovation Research Grant')->first();

        if ($accepted) {
            $row = Application::updateOrCreate(
                ['user_id' => $incompleteApplicant->user_id, 'opportunity_id' => $accepted->opportunity_id],
                [
                    'application_status' => ApplicationStatus::ACCEPTED,
                    'submitted_at' => Carbon::now()->subDays(18),
                    'decided_at' => Carbon::now()->subDays(11),
                    'decision_reason' => 'Strong local ties and a clear need. Welcome to the programme.',
                    'personal_statement' => 'I grew up in Manicaland and want to qualify as a doctor for the '
                        . 'communities that raised me. This fund would let me complete A-Level without financial strain.',
                ]
            );

            $this->applicationEvent($row, $accepted, $incompleteApplicant, ApplicationStatus::ACCEPTED);
        }

        if ($rejected) {
            $row = Application::updateOrCreate(
                ['user_id' => $incompleteApplicant->user_id, 'opportunity_id' => $rejected->opportunity_id],
                [
                    'application_status' => ApplicationStatus::REJECTED,
                    'submitted_at' => Carbon::now()->subDays(15),
                    'decided_at' => Carbon::now()->subDays(9),
                    'decision_reason' => 'This grant is reserved for PhD-level agribusiness research. Please reapply '
                        . 'once you have progressed beyond A-Level.',
                    'personal_statement' => 'I am interested in agribusiness as a possible second field alongside medicine.',
                ]
            );

            $this->applicationEvent($row, $rejected, $incompleteApplicant, ApplicationStatus::REJECTED);
        }
    }

    /**
     * The notification a real decision would have sent, written directly.
     * Wording matches ApplicationService::notifyApplicantOfDecision() exactly,
     * so the notification centre shows the same message a live decision would
     * produce; only the network call to Mailgun is skipped.
     *
     * Idempotent by delete-then-insert rather than updateOrCreate, because a
     * (user, type, related_id) triple is not a database key here the way
     * (email) is on users - re-seeding must not leave two copies behind.
     */
    private function applicationEvent(Application $application, Opportunity $opportunity, User $applicant, string $status): void
    {
        $reason = $application->decision_reason;

        [$type, $message] = $status === ApplicationStatus::ACCEPTED
            ? [NotificationType::APPLICATION_ACCEPTED, 'Congratulations! Your application to "' . $opportunity->title . '" has been accepted. ' . $reason]
            : [NotificationType::APPLICATION_REJECTED, 'Your application to "' . $opportunity->title . '" was not successful. ' . $reason];

        Notification::where('user_id', $applicant->user_id)
            ->where('type', $type)
            ->where('related_id', $application->application_id)
            ->delete();

        Notification::create([
            'user_id' => $applicant->user_id,
            'type' => $type,
            'message' => trim($message),
            'link' => '/applications/' . $application->application_id . '/confirmation',
            'related_id' => $application->application_id,
            'is_read' => false,
            'created_at' => $application->decided_at,
        ]);
    }

    /**
     * A handful of real audit rows so the admin audit log is not empty on
     * first login. Each mirrors an action the seeded data represents as
     * already having happened - the active provider's approval, the two
     * application decisions above - written through AuditService so the
     * shape (redaction, actor_user_id resolution) matches a live entry
     * exactly rather than being hand-assembled.
     */
    private function auditTrail(User $admin, User $activeProvider, User $pendingProvider): void
    {
        $audit = app(\App\Services\AuditService::class);

        AuditLog::where('action', AuditAction::APPROVE_PROVIDER)
            ->where('entity_id', $activeProvider->user_id)
            ->delete();
        $audit->log(
            $admin->email,
            AuditAction::APPROVE_PROVIDER,
            'USER',
            $activeProvider->user_id,
            'Approved provider registration for ' . $activeProvider->full_name
        );

        AuditLog::where('action', AuditAction::REGISTER)
            ->where('entity_id', $pendingProvider->user_id)
            ->delete();
        $audit->log(
            $pendingProvider->email,
            AuditAction::REGISTER,
            'USER',
            $pendingProvider->user_id,
            'Registered as a provider: ' . $pendingProvider->full_name
        );

        // Deliberately not seeding a STATUS_UPDATE row for the two decided
        // applications below, even though a real decision would write one.
        // ApplicationConcurrencyTest asserts an exact *global*
        // AuditLog::where('action', STATUS_UPDATE)->count() to prove a failed
        // or duplicate decision writes no extra audit entry - a correctness
        // property, not an incidental number - and a seeded row of the same
        // action type is indistinguishable from the bug that assertion exists
        // to catch. The admin audit log still has real content to show
        // (APPROVE_PROVIDER and REGISTER above), and a viva walkthrough
        // generates real STATUS_UPDATE rows the moment a decision is made live.
    }
}
