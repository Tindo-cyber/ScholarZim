<?php

namespace Tests\Feature;

use App\Models\ApplicantProfile;
use App\Models\Application;
use App\Models\Opportunity;
use App\Models\User;
use App\Support\EducationLevel;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The migration that gives the Grade 7 slip a slot of its own and records what each application was sent with.
 * Run against data from before it existed: it is taken down first, the old-shaped rows are made, then it runs.
 */
class Grade7SlotMigrationTest extends TestCase
{
    use RefreshDatabase;

    private object $migration;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->migration = require database_path('migrations/2025_01_01_000017_grade7_slip_slot_and_application_documents.php');
    }

    private function profileOf(string $email): ApplicantProfile
    {
        return ApplicantProfile::where('user_id', User::where('email', $email)->value('user_id'))->firstOrFail();
    }

    private function applicationFor(string $email): Application
    {
        return Application::create([
            'user_id' => User::where('email', $email)->value('user_id'),
            'opportunity_id' => Opportunity::query()->value('opportunity_id'),
            'application_status' => 'PENDING', 'personal_statement' => str_repeat('x', 120), 'submitted_at' => now(),
        ]);
    }

    public function test_a_pupils_file_in_the_certificate_slot_moves_to_the_slip_slot(): void
    {
        $this->migration->down();
        $this->profileOf('kudzai.marufu@scholarzim.co.zw')->update([
            'results_certificate_path' => 'profiles/1/slip.pdf', 'results_certificate_filename' => 'Results Certificate.pdf', 'results_uploaded_at' => '2026-05-01 10:00:00',
        ]);

        $this->migration->up();

        $profile = $this->profileOf('kudzai.marufu@scholarzim.co.zw');
        $this->assertSame('profiles/1/slip.pdf', $profile->grade7_slip_path);
        $this->assertSame('Results Certificate.pdf', $profile->grade7_slip_filename);
        $this->assertNull($profile->results_certificate_path);
    }

    public function test_everyone_elses_certificate_stays_where_it_is(): void
    {
        $this->migration->down();
        $this->profileOf('farai.sibanda@scholarzim.co.zw')->update(['results_certificate_path' => 'profiles/2/o-level.pdf']);

        $this->migration->up();

        $profile = $this->profileOf('farai.sibanda@scholarzim.co.zw');
        $this->assertSame('profiles/2/o-level.pdf', $profile->results_certificate_path);
        $this->assertNull($profile->grade7_slip_path);
    }

    public function test_each_existing_application_gets_a_record_of_its_applicants_documents(): void
    {
        $this->migration->down();
        $this->profileOf('student@scholarzim.co.zw')->update(['transcript_path' => 'profiles/3/t.pdf', 'transcript_filename' => 'Transcript.pdf', 'passport_path' => 'profiles/3/p.pdf']);
        $application = $this->applicationFor('student@scholarzim.co.zw');

        $this->migration->up();

        $rows = DB::table('application_documents')->where('application_id', $application->application_id)->get();
        $this->assertSame(['transcript'], $rows->pluck('type')->all(), 'only what a provider can open');
        $this->assertSame('profiles/3/t.pdf', $rows->first()->path);
        $this->assertSame('Transcript.pdf', $rows->first()->filename);
    }

    public function test_an_applicant_with_no_documents_gets_no_rows(): void
    {
        $this->migration->down();
        $application = $this->applicationFor('farai.sibanda@scholarzim.co.zw');
        $this->profileOf('farai.sibanda@scholarzim.co.zw')->update(['results_certificate_path' => null, 'transcript_path' => null]);

        $this->migration->up();

        $this->assertSame(0, DB::table('application_documents')->where('application_id', $application->application_id)->count());
    }

    public function test_it_is_reversible_and_puts_a_pupils_slip_back(): void
    {
        $this->migration->down();
        $this->profileOf('kudzai.marufu@scholarzim.co.zw')->update(['results_certificate_path' => 'profiles/1/slip.pdf']);
        $this->migration->up();

        $this->migration->down();

        $this->assertFalse(Schema::hasTable('application_documents'));
        $this->assertFalse(Schema::hasColumn('applicant_profiles', 'grade7_slip_path'));
        $this->assertSame('profiles/1/slip.pdf', DB::table('applicant_profiles')->where('user_id', User::where('email', 'kudzai.marufu@scholarzim.co.zw')->value('user_id'))->value('results_certificate_path'));
    }

    public function test_it_never_touches_a_file(): void
    {
        \Illuminate\Support\Facades\Storage::fake((string) config('filesystems.default', 'local'));
        \Illuminate\Support\Facades\Storage::disk((string) config('filesystems.default', 'local'))->put('profiles/1/slip.pdf', 'x');
        $this->migration->down();
        $this->profileOf('kudzai.marufu@scholarzim.co.zw')->update(['results_certificate_path' => 'profiles/1/slip.pdf']);

        $this->migration->up();
        $this->migration->down();

        $this->assertTrue(\Illuminate\Support\Facades\Storage::disk((string) config('filesystems.default', 'local'))->exists('profiles/1/slip.pdf'));
    }
}
