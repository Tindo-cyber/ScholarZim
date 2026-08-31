<?php

namespace Database\Seeders;

use App\Models\ApplicantProfile;
use App\Models\Application;
use App\Models\Opportunity;
use App\Models\ProviderProfile;
use App\Models\Role;
use App\Models\User;
use App\Support\AccountStatus;
use App\Support\ApplicationStatus;
use App\Support\FormOptions;
use App\Support\OpportunityModerationStatus;
use App\Support\OpportunityStatus;
use App\Support\ProviderOrgType;
use App\Support\RoleNames;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->ensureRoles();

        $this->admin();
        $provider = $this->provider();
        $applicant = $this->applicant();

        $this->opportunities($provider);
        $this->sampleApplication($applicant);

        $this->command->info('Seeded admin, provider, applicant, and demo listings.');
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
                'education_level' => 'Undergraduate',
                'institution_name' => 'University of Zimbabwe (UZ)',
                'field_of_study' => 'Computer Science & IT',
                'country' => FormOptions::DEFAULT_COUNTRY,
                'province' => 'Harare',
                'date_of_birth' => Carbon::today()->subYears(21)->toDateString(),
                'citizenship' => 'Zimbabwean',
                'academic_results' => '14 points at A-Level (Maths A, Physics A, Computer Science B)',
                'biography' => 'Second-year computing student building civic-tech projects for rural schools.',
                'results_certificate_path' => 'profiles/demo/results.pdf',
                'results_certificate_filename' => 'a-level-results.pdf',
                'results_uploaded_at' => Carbon::now()->subMonth(),
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

    private function opportunities(User $provider): void
    {
        $listings = [
            ['Zimbabwe Tech Futures Undergraduate Bursary', 'Computer Science & IT', 'Undergraduate', 'Full Scholarship', 45],
            ['Midlands Engineering Excellence Award', 'Engineering', 'Undergraduate', 'Tuition Only', 12],
            ['Harare Health Sciences Postgraduate Grant', 'Medicine & Health Sciences', 'Masters', 'Tuition + Accommodation', 90],
            ['Rural Schools A-Level Support Fund', 'General Secondary', 'High School (A-Level)', 'Partial Scholarship', 30],
            ['Agribusiness Innovation Research Grant', 'Agriculture & Agribusiness', 'PhD', 'Research Grant', 120],
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

        // One listing left in the queue so the admin moderation panel has content.
        Opportunity::updateOrCreate(
            ['title' => 'Bulawayo Mining Skills Scholarship'],
            [
                'provider_user_id' => $provider->user_id,
                'provider_name' => $provider->full_name,
                'description' => 'A new award for mining and metallurgy students, awaiting administrator review.',
                'education_level' => 'Diploma',
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

    private function sampleApplication(User $applicant): void
    {
        $opportunity = Opportunity::where('title', 'Midlands Engineering Excellence Award')->first();

        if (! $opportunity) {
            return;
        }

        Application::updateOrCreate(
            ['user_id' => $applicant->user_id, 'opportunity_id' => $opportunity->opportunity_id],
            [
                'application_status' => ApplicationStatus::PENDING,
                'submitted_at' => Carbon::now()->subDays(5),
                'personal_statement' => 'I am applying because this award would let me finish my degree without '
                    . 'interrupting my studies to work. I intend to build software for Zimbabwean schools.',
            ]
        );
    }
}
