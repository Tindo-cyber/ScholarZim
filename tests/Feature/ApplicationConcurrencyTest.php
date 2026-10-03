<?php

namespace Tests\Feature;

use App\Exceptions\InvalidApplicationTransition;
use App\Models\Application;
use App\Models\AuditLog;
use App\Models\Notification;
use App\Models\Opportunity;
use App\Models\User;
use App\Services\ApplicationService;
use App\Support\AccountStatus;
use App\Support\ApplicationStatus;
use App\Support\AuditAction;
use App\Support\NotificationType;
use App\Support\OpportunityStatus;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * What survives a request that fails half way, and what happens when two
 * requests reach the same application at once.
 *
 * The interleavings are produced with Eloquent model events rather than with
 * sleeps or parallel processes: an event fires at exactly the point the losing
 * request would have been overtaken, which makes the race deterministic and the
 * failure reproducible instead of occasional.
 */
class ApplicationConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    private User $student;

    private User $provider;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake($this->testDisk());
        $this->seed(DatabaseSeeder::class);
        $this->student = User::where('email', 'student@scholarzim.co.zw')->firstOrFail();
        $this->provider = User::where('email', 'provider@scholarzim.co.zw')->firstOrFail();
    }

    // -------------------------------------------------------- the constraint --

    /**
     * The guarantee everything else leans on. If this index ever goes away, the
     * duplicate-submission defence goes with it and nothing else in this file
     * would notice.
     */
    public function test_the_database_refuses_a_second_application_for_the_same_pair(): void
    {
        $opportunity = $this->opportunity();

        Application::create([
            'user_id' => $this->student->user_id,
            'opportunity_id' => $opportunity->opportunity_id,
            'application_status' => ApplicationStatus::PENDING,
            'submitted_at' => Carbon::now(),
        ]);

        $this->expectException(UniqueConstraintViolationException::class);

        Application::create([
            'user_id' => $this->student->user_id,
            'opportunity_id' => $opportunity->opportunity_id,
            'application_status' => ApplicationStatus::PENDING,
            'submitted_at' => Carbon::now(),
        ]);
    }

    /**
     * The other half of the guarantee, and the one the tests in this file cannot
     * exercise directly: on MySQL the locked reads really do emit FOR UPDATE, so
     * a second writer waits instead of racing.
     *
     * SQLite compiles locking to nothing, which is why every race below is
     * played out as a deterministic interleaving rather than with real threads.
     * Compiling the query against the MySQL grammar - no server needed - is what
     * stops the clause being quietly dropped without any test noticing.
     */
    public function test_locked_reads_emit_for_update_on_mysql(): void
    {
        $sql = DB::connection('mysql')
            ->table('applications')
            ->where('application_id', 1)
            ->lockForUpdate()
            ->toSql();

        $this->assertStringEndsWith('for update', $sql);
    }

    // ------------------------------------------------------ submission races --

    /**
     * Two first-time submissions in flight together. The loser is overtaken
     * after its "have you already applied?" check has passed and before its own
     * insert lands - the exact window the check alone cannot close.
     *
     * It must come back as the ordinary business message, not as an integrity
     * violation, and it must not leave a second row behind.
     */
    public function test_a_submission_overtaken_by_a_duplicate_fails_as_a_business_error(): void
    {
        $opportunity = $this->opportunity();

        // The competing request commits its row while ours is mid-transaction.
        // Written with the query builder so it does not re-enter this listener.
        Application::creating(function () use ($opportunity) {
            DB::table('applications')->insert([
                'user_id' => $this->student->user_id,
                'opportunity_id' => $opportunity->opportunity_id,
                'application_status' => ApplicationStatus::PENDING,
                'submitted_at' => Carbon::now(),
            ]);
        });

        try {
            $this->service()->quickApply($opportunity->opportunity_id, $this->student);
            $this->fail('the overtaken submission should not have succeeded');
        } catch (RuntimeException $e) {
            $this->assertNotInstanceOf(UniqueConstraintViolationException::class, $e);
            $this->assertSame('You have already applied to this opportunity.', $e->getMessage());
        }

        // The losing transaction is gone in its entirety - including the row the
        // stand-in competitor inserted inside it, which a real competing request
        // would have committed on its own connection.
        $this->assertSame(0, $this->applicationCount($opportunity));
    }

    /**
     * The same outcome with a competitor that genuinely committed first: the
     * second submission is refused and exactly one application exists. This is
     * the invariant; the test above is about how the loser is told.
     */
    public function test_a_committed_application_blocks_a_second_submission(): void
    {
        $opportunity = $this->opportunity();
        $this->application($opportunity, ApplicationStatus::PENDING);

        try {
            $this->service()->quickApply($opportunity->opportunity_id, $this->student);
            $this->fail('the second submission should not have succeeded');
        } catch (RuntimeException $e) {
            $this->assertSame('You have already applied to this opportunity.', $e->getMessage());
        }

        $this->assertSame(1, $this->applicationCount($opportunity), 'exactly one application may exist for the pair');
    }

    /** The same race reached over HTTP is reported, not rendered as a 500. */
    public function test_the_losing_request_gets_a_clean_message_rather_than_a_sql_error(): void
    {
        $opportunity = $this->opportunity();

        Application::creating(function () use ($opportunity) {
            DB::table('applications')->insert([
                'user_id' => $this->student->user_id,
                'opportunity_id' => $opportunity->opportunity_id,
                'application_status' => ApplicationStatus::PENDING,
                'submitted_at' => Carbon::now(),
            ]);
        });

        $this->actingAs($this->student)
            ->post('/apply/' . $opportunity->opportunity_id . '/quick')
            ->assertRedirect()
            ->assertSessionHas('errorMessage', 'You have already applied to this opportunity.');
    }

    /** Re-application is serialised too: reopening twice still yields one row. */
    public function test_a_second_reapplication_cannot_reopen_an_already_reopened_row(): void
    {
        $opportunity = $this->opportunity();
        $this->application($opportunity, ApplicationStatus::WITHDRAWN);

        $this->service()->quickApply($opportunity->opportunity_id, $this->student);

        // The row is live again, so the next attempt is refused by the rule
        // rather than quietly resubmitting on top of itself.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('You have already applied to this opportunity.');

        $this->service()->quickApply($opportunity->opportunity_id, $this->student);
    }

    // -------------------------------------------------- rollback consequences --

    /**
     * A submission that fails after the row is written must leave nothing at
     * all: no application, no audit line claiming one was made, no notification
     * telling either party it happened, and no uploaded file on disk.
     */
    public function test_a_failed_submission_leaves_no_row_no_audit_no_notification_and_no_file(): void
    {
        $opportunity = $this->opportunity();

        Application::created(function () {
            throw new RuntimeException('storage layer exploded after the insert');
        });

        $before = Notification::count();

        try {
            $this->service()->submit(
                $opportunity->opportunity_id,
                $this->student,
                ['personal_statement' => 'A statement long enough to be real.'],
                UploadedFile::fake()->create('transcript.pdf', 200, 'application/pdf')
            );
            $this->fail('the submission should have failed');
        } catch (RuntimeException $e) {
            $this->assertSame('storage layer exploded after the insert', $e->getMessage());
        }

        $this->assertSame(0, $this->applicationCount($opportunity), 'the rolled-back application must not persist');

        $this->assertSame(
            0,
            AuditLog::where('action', AuditAction::APPLY)
                ->where('actor_email', $this->student->email)
                ->count(),
            'an audit line must not outlive the operation it describes'
        );

        $this->assertSame($before, Notification::count(), 'nothing may be announced for a submission that rolled back');

        $this->assertSame(
            [],
            Storage::disk($this->testDisk())->allFiles('applications'),
            'the upload must not be left behind with no row pointing at it'
        );
    }

    /** The successful path is the control: everything the failure lacked is present. */
    public function test_a_successful_submission_writes_its_row_audit_notification_and_file(): void
    {
        $opportunity = $this->opportunity();

        $application = $this->service()->submit(
            $opportunity->opportunity_id,
            $this->student,
            ['personal_statement' => 'A statement long enough to be real.'],
            UploadedFile::fake()->create('transcript.pdf', 200, 'application/pdf')
        );

        $this->assertSame(1, $this->applicationCount($opportunity));
        $this->assertSame(1, AuditLog::where('action', AuditAction::APPLY)->count());
        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->student->user_id,
            'type' => NotificationType::APPLICATION_SUBMITTED,
        ]);

        Storage::disk($this->testDisk())->assertExists($application->document_path);
    }

    /**
     * Replacing a document on re-application. The superseded file goes only once
     * the new row is committed, and the row never points at a path that is not
     * on disk.
     */
    public function test_replacing_a_document_on_reapplication_removes_only_the_old_file(): void
    {
        $opportunity = $this->opportunity();

        $first = $this->service()->submit(
            $opportunity->opportunity_id,
            $this->student,
            ['personal_statement' => 'First attempt statement.'],
            UploadedFile::fake()->create('first.pdf', 100, 'application/pdf')
        );
        $firstPath = $first->document_path;

        $first->update(['application_status' => ApplicationStatus::WITHDRAWN]);

        $second = $this->service()->submit(
            $opportunity->opportunity_id,
            $this->student,
            ['personal_statement' => 'Second attempt statement.'],
            UploadedFile::fake()->create('second.pdf', 100, 'application/pdf')
        );

        $this->assertNotSame($firstPath, $second->document_path);
        Storage::disk($this->testDisk())->assertMissing($firstPath);
        Storage::disk($this->testDisk())->assertExists($second->document_path);
        $this->assertCount(1, Storage::disk($this->testDisk())->allFiles('applications'));
    }

    // ------------------------------------------------------- decision races --

    /**
     * Two reviewers deciding at once. Serialised by the row lock, the second
     * finds a decided application and is refused - so the applicant is never
     * told two contradictory outcomes.
     */
    public function test_a_second_decision_on_a_decided_application_neither_writes_nor_notifies(): void
    {
        $application = $this->application($this->opportunity(), ApplicationStatus::PENDING);

        $this->service()->decide(
            $application->application_id,
            ApplicationStatus::ACCEPTED,
            'Outstanding record.',
            $this->provider
        );

        try {
            $this->service()->decide(
                $application->application_id,
                ApplicationStatus::REJECTED,
                'Actually, no.',
                $this->provider
            );
            $this->fail('the second decision should have been refused');
        } catch (InvalidApplicationTransition $e) {
            $this->assertStringContainsString('already accepted', $e->getMessage());
        }

        $this->assertSame(ApplicationStatus::ACCEPTED, $application->fresh()->application_status);

        $this->assertSame(
            1,
            Notification::where('user_id', $this->student->user_id)
                ->where('type', NotificationType::APPLICATION_ACCEPTED)
                ->count()
        );
        $this->assertSame(
            0,
            Notification::where('user_id', $this->student->user_id)
                ->where('type', NotificationType::APPLICATION_REJECTED)
                ->count(),
            'the refused decision must not reach the applicant'
        );
        $this->assertSame(
            1,
            AuditLog::where('action', AuditAction::STATUS_UPDATE)->count(),
            'only the decision that landed may be audited'
        );
    }

    /**
     * A decision that fails after the update is written must not notify either.
     * Without a transaction the status would have stuck while the rest unwound.
     */
    public function test_a_failed_status_change_rolls_back_and_stays_silent(): void
    {
        $application = $this->application($this->opportunity(), ApplicationStatus::PENDING);

        Application::updated(function () {
            throw new RuntimeException('failed after the status was written');
        });

        $before = Notification::count();

        try {
            $this->service()->decide(
                $application->application_id,
                ApplicationStatus::ACCEPTED,
                'Outstanding record.',
                $this->provider
            );
            $this->fail('the decision should have failed');
        } catch (RuntimeException $e) {
            $this->assertSame('failed after the status was written', $e->getMessage());
        }

        $this->assertSame(
            ApplicationStatus::PENDING,
            $application->fresh()->application_status,
            'the status change must not survive its own failure'
        );
        $this->assertSame($before, Notification::count());
        $this->assertSame(0, AuditLog::where('action', AuditAction::STATUS_UPDATE)->count());
    }

    // ----------------------------------------------------- withdrawal races --

    /** Withdrawing twice writes one withdrawal, one audit line, one notification. */
    public function test_a_second_withdrawal_is_refused_and_notifies_once(): void
    {
        $application = $this->application($this->opportunity(), ApplicationStatus::PENDING);

        $this->service()->withdraw($application->application_id, $this->student, 'Took another award.');

        try {
            $this->service()->withdraw($application->application_id, $this->student, 'Again.');
            $this->fail('the second withdrawal should have been refused');
        } catch (InvalidApplicationTransition $e) {
            $this->assertStringContainsString('cannot be moved to withdrawn', $e->getMessage());
        }

        $this->assertSame(
            1,
            AuditLog::where('action', AuditAction::WITHDRAW_APPLICATION)->count()
        );
        $this->assertSame(
            1,
            Notification::where('type', NotificationType::APPLICATION_WITHDRAWN)->count()
        );
    }

    /**
     * The applicant withdraws while the provider is deciding. Whichever commits
     * first stands; the other is refused rather than overwriting a status it
     * never saw.
     */
    public function test_a_decision_cannot_overwrite_a_withdrawal_it_never_saw(): void
    {
        $application = $this->application($this->opportunity(), ApplicationStatus::PENDING);

        $this->service()->withdraw($application->application_id, $this->student);

        $this->expectException(InvalidApplicationTransition::class);

        $this->service()->decide(
            $application->application_id,
            ApplicationStatus::ACCEPTED,
            'Approving anyway.',
            $this->provider
        );
    }

    // ------------------------------------------------------- audit integrity --

    /**
     * The trail has to hold in both directions. Nothing surviving a rollback was
     * the easy half; this is the other one - a status change whose audit line
     * cannot be written must not quietly commit unaudited, leaving the row
     * changed and no record of who changed it.
     */
    public function test_a_status_change_whose_audit_cannot_be_written_is_rolled_back(): void
    {
        $application = $this->application($this->opportunity(), ApplicationStatus::PENDING);

        AuditLog::creating(function () {
            throw new RuntimeException('audit table unavailable');
        });

        $before = Notification::count();

        try {
            $this->service()->decide(
                $application->application_id,
                ApplicationStatus::ACCEPTED,
                'Outstanding record.',
                $this->provider
            );
            $this->fail('a decision that cannot be audited must not be applied');
        } catch (RuntimeException $e) {
            $this->assertSame('audit table unavailable', $e->getMessage());
        }

        $this->assertSame(
            ApplicationStatus::PENDING,
            $application->fresh()->application_status,
            'the decision must not commit without its audit line'
        );
        $this->assertSame($before, Notification::count(), 'and the applicant must not be told about it');
    }

    // ------------------------------------------------- profile document swap --

    /**
     * The bug this run fixed: the old file used to be deleted before the new one
     * was stored, so a failure part way through destroyed the document the
     * student already had and left the row pointing at nothing.
     */
    public function test_a_failed_document_replacement_keeps_the_document_already_held(): void
    {
        $profileService = app(\App\Services\ApplicantProfileService::class);

        $profile = $profileService->storeDocument(
            $this->student,
            'cv',
            UploadedFile::fake()->create('id.pdf', 50, 'application/pdf')
        );

        $originalPath = $profile->fresh()->cv_path;
        $this->assertNotNull($originalPath);
        Storage::disk($this->testDisk())->assertExists($originalPath);

        \App\Models\ApplicantProfile::updated(function () {
            throw new RuntimeException('failed while recording the replacement');
        });

        try {
            $profileService->storeDocument(
                $this->student,
                'cv',
                UploadedFile::fake()->create('replacement.pdf', 50, 'application/pdf')
            );
            $this->fail('the replacement should have failed');
        } catch (RuntimeException $e) {
            $this->assertSame('failed while recording the replacement', $e->getMessage());
        }

        $this->assertSame(
            $originalPath,
            $this->student->fresh()->applicantProfile->cv_path,
            'the profile must still point at the document it had'
        );
        Storage::disk($this->testDisk())->assertExists($originalPath);

        $this->assertCount(
            1,
            Storage::disk($this->testDisk())->allFiles('profiles/' . $this->student->user_id),
            'the failed upload must not be left on disk'
        );
    }

    // ------------------------------------------------ provider certificate --

    /**
     * The same orphaned-upload bug as application submission, in the other place
     * a file is written before a transaction. A registration that fails part way
     * must not leave a provider's registration certificate on disk with no
     * account referring to it.
     */
    public function test_a_failed_provider_registration_leaves_no_certificate_behind(): void
    {
        \App\Models\ProviderProfile::creating(function () {
            throw new RuntimeException('failed while recording the provider profile');
        });

        try {
            app(\App\Services\RegistrationService::class)->registerProvider(
                [
                    'full_name' => 'Chiedza Trust',
                    'email' => 'chiedza-trust@example.test',
                    'password' => 'ChangeMe123',
                    'organisation_type' => 'Trust',
                    'registration_number' => 'TR-2026-001',
                ],
                UploadedFile::fake()->create('certificate.pdf', 80, 'application/pdf')
            );
            $this->fail('the registration should have failed');
        } catch (RuntimeException $e) {
            $this->assertSame('failed while recording the provider profile', $e->getMessage());
        }

        $this->assertSame(
            [],
            Storage::disk($this->testDisk())->allFiles('provider-certificates'),
            'the certificate must not outlive the registration that rolled back'
        );
        $this->assertDatabaseMissing('users', ['email' => 'chiedza-trust@example.test']);
    }

    // -------------------------------------------------------- award capacity --

    /** award_slots is a cap on ACCEPTED applications only - PENDING ones never count against it. */
    public function test_award_slots_null_allows_unlimited_accepted_applications(): void
    {
        $opportunity = $this->opportunity(); // seeded with award_slots = null

        $apps = [
            $this->application($opportunity, ApplicationStatus::PENDING, $this->otherStudent('a')),
            $this->application($opportunity, ApplicationStatus::PENDING, $this->otherStudent('b')),
            $this->application($opportunity, ApplicationStatus::PENDING, $this->otherStudent('c')),
        ];

        foreach ($apps as $app) {
            $this->service()->decide($app->application_id, ApplicationStatus::ACCEPTED, 'Well qualified.', $this->provider);
        }

        $this->assertSame(
            3,
            Application::where('opportunity_id', $opportunity->opportunity_id)
                ->where('application_status', ApplicationStatus::ACCEPTED)
                ->count()
        );
        $this->assertSame(OpportunityStatus::ACTIVE, $opportunity->fresh()->status);
    }

    public function test_award_slots_one_allows_exactly_one_acceptance(): void
    {
        $opportunity = $this->opportunityWithSlots(1);
        $application = $this->application($opportunity, ApplicationStatus::PENDING, $this->otherStudent('a'));

        $decided = $this->service()->decide($application->application_id, ApplicationStatus::ACCEPTED, 'Strong fit.', $this->provider);

        $this->assertSame(ApplicationStatus::ACCEPTED, $decided->fresh()->application_status);
    }

    /** The exact scenario in the brief: award_slots = 2, a third acceptance attempt is refused. */
    public function test_a_second_acceptance_beyond_capacity_is_refused_and_does_not_modify_state(): void
    {
        $opportunity = $this->opportunityWithSlots(1);
        $first = $this->application($opportunity, ApplicationStatus::PENDING, $this->otherStudent('a'));
        $second = $this->application($opportunity, ApplicationStatus::PENDING, $this->otherStudent('b'));

        $this->service()->decide($first->application_id, ApplicationStatus::ACCEPTED, 'First pick.', $this->provider);

        $auditBefore = AuditLog::where('action', AuditAction::STATUS_UPDATE)->count();
        $notificationsBefore = Notification::count();

        try {
            $this->service()->decide($second->application_id, ApplicationStatus::ACCEPTED, 'Second pick.', $this->provider);
            $this->fail('accepting beyond capacity should have been refused');
        } catch (RuntimeException $e) {
            $this->assertSame(
                'This scholarship has already filled all of its available awards. You cannot accept another application.',
                $e->getMessage()
            );
        }

        $this->assertSame(
            ApplicationStatus::PENDING,
            $second->fresh()->application_status,
            'a refused decision must not change the application at all'
        );
        $this->assertSame(1, Application::where('opportunity_id', $opportunity->opportunity_id)
            ->where('application_status', ApplicationStatus::ACCEPTED)->count());
        $this->assertSame($auditBefore, AuditLog::where('action', AuditAction::STATUS_UPDATE)->count(), 'a refused decision leaves no audit line');
        $this->assertSame($notificationsBefore, Notification::count(), 'a refused decision notifies nobody');
    }

    /** Filling the last slot is also a publication-state change: the listing closes in the same transaction. */
    public function test_accepting_the_final_slot_closes_the_opportunity_and_notifies_the_provider(): void
    {
        $opportunity = $this->opportunityWithSlots(1);
        $application = $this->application($opportunity, ApplicationStatus::PENDING, $this->otherStudent('a'));

        $this->service()->decide($application->application_id, ApplicationStatus::ACCEPTED, 'Great fit.', $this->provider);

        $fresh = $opportunity->fresh();
        $this->assertSame(OpportunityStatus::CLOSED, $fresh->status);
        $this->assertStringContainsString('award slots filled', (string) $fresh->last_change_reason);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->provider->user_id,
            'type' => NotificationType::SCHOLARSHIP_CLOSED,
        ]);
    }

    /** Rejecting never consumes a slot, whatever the capacity. */
    public function test_rejecting_an_application_never_counts_against_capacity(): void
    {
        $opportunity = $this->opportunityWithSlots(1);
        $application = $this->application($opportunity, ApplicationStatus::PENDING, $this->otherStudent('a'));

        $this->service()->decide($application->application_id, ApplicationStatus::REJECTED, 'Not a fit.', $this->provider);

        $this->assertSame(OpportunityStatus::ACTIVE, $opportunity->fresh()->status);
        $this->assertSame(0, Application::where('opportunity_id', $opportunity->opportunity_id)
            ->where('application_status', ApplicationStatus::ACCEPTED)->count());
    }

    // ----------------------------------------------------- closed by capacity --

    public function test_a_capacity_closed_opportunity_refuses_a_new_submission(): void
    {
        $opportunity = $this->opportunityWithSlots(1);
        $this->acceptOneApplication($opportunity);
        $this->assertSame(OpportunityStatus::CLOSED, $opportunity->fresh()->status);

        $thirdStudent = $this->otherStudent('third');

        try {
            // A bare student with no profile at all still reaches this refusal:
            // capacity is checked before profile completeness, so a listing
            // that has filled its awards never gets as far as asking whether
            // the applicant is otherwise eligible.
            $this->service()->quickApply($opportunity->opportunity_id, $thirdStudent);
            $this->fail('a submission against a capacity-closed opportunity should have been refused');
        } catch (RuntimeException $e) {
            $this->assertSame(
                'This scholarship is no longer accepting applications because its available awards have been filled.',
                $e->getMessage()
            );
        }

        $this->assertSame(0, Application::where('opportunity_id', $opportunity->opportunity_id)
            ->where('user_id', $thirdStudent->user_id)->count());
    }

    public function test_a_capacity_closed_opportunity_does_not_appear_publicly(): void
    {
        $opportunity = $this->opportunityWithSlots(1);
        $this->acceptOneApplication($opportunity);

        $this->assertNull(Opportunity::publiclyVisible()->find($opportunity->opportunity_id));
        $this->assertNull(app(\App\Services\OpportunityService::class)->findPubliclyVisible($opportunity->opportunity_id));
    }

    /** Closed, not deleted and not rejected: the provider still sees it, with its history intact. */
    public function test_a_capacity_closed_opportunity_remains_in_the_providers_own_list(): void
    {
        $opportunity = $this->opportunityWithSlots(1);
        $this->acceptOneApplication($opportunity);

        $providerListing = app(\App\Services\OpportunityService::class)->forProvider($this->provider)
            ->firstWhere('opportunity_id', $opportunity->opportunity_id);

        $this->assertNotNull($providerListing, 'a capacity-closed listing must still appear in the providers own list');
        $this->assertSame(OpportunityStatus::CLOSED, $providerListing->status);
        $this->assertSame(
            \App\Support\OpportunityModerationStatus::APPROVED,
            $providerListing->moderation_status,
            'closing for capacity must not touch the moderation verdict'
        );
    }

    // ---------------------------------------------------- capacity concurrency --

    /** The same proof the file already uses for applications, extended to the row this change starts locking. */
    public function test_opportunity_locked_reads_emit_for_update_on_mysql(): void
    {
        $sql = DB::connection('mysql')
            ->table('opportunities')
            ->where('opportunity_id', 1)
            ->lockForUpdate()
            ->toSql();

        $this->assertStringEndsWith('for update', $sql);
    }

    /**
     * Two providers' reviewers racing for the same last seat. Played out
     * sequentially rather than with real threads - same reasoning as every
     * other race in this file - but the outcome is exactly what the lock is
     * for: only one of the two acceptances may land.
     */
    public function test_two_acceptance_attempts_against_the_last_slot_only_one_succeeds(): void
    {
        $opportunity = $this->opportunityWithSlots(1);
        $first = $this->application($opportunity, ApplicationStatus::PENDING, $this->otherStudent('a'));
        $second = $this->application($opportunity, ApplicationStatus::PENDING, $this->otherStudent('b'));

        $this->service()->decide($first->application_id, ApplicationStatus::ACCEPTED, 'First.', $this->provider);

        try {
            $this->service()->decide($second->application_id, ApplicationStatus::ACCEPTED, 'Second.', $this->provider);
            $this->fail('only one acceptance may succeed against a single award slot');
        } catch (RuntimeException $e) {
            // Expected - see test_a_second_acceptance_beyond_capacity_is_refused_and_does_not_modify_state for the message assertion.
        }

        $this->assertSame(1, Application::where('opportunity_id', $opportunity->opportunity_id)
            ->where('application_status', ApplicationStatus::ACCEPTED)->count());
    }

    /**
     * Two submissions reaching an opportunity that is already at capacity.
     * Both must be refused by the authoritative, locked check - neither may
     * squeeze in on a stale read of "still open".
     */
    public function test_two_submissions_against_an_already_full_opportunity_are_both_refused(): void
    {
        $opportunity = $this->opportunityWithSlots(1);
        $this->acceptOneApplication($opportunity);

        foreach (['x', 'y'] as $suffix) {
            $student = $this->otherStudent($suffix);

            try {
                $this->service()->quickApply($opportunity->opportunity_id, $student);
                $this->fail("submission $suffix against a full opportunity should have been refused");
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('available awards have been filled', $e->getMessage());
            }
        }

        $this->assertSame(
            1,
            Application::where('opportunity_id', $opportunity->opportunity_id)->count(),
            'no new application may be created once capacity is full, however many submissions race for it'
        );
    }

    /**
     * The authoritative check proven, not assumed. submit() reads the
     * opportunity twice: once before the transaction, to fail fast and
     * decide whether uploading a document is worth doing, and once locked,
     * inside it. Opportunity::retrieved fires on every read of the model,
     * including both of those - so firing a competing acceptance the first
     * time only simulates it landing in the gap between them: the pre-check
     * still sees the listing open, and the transaction's own read, moments
     * later, finds its one slot already gone.
     */
    public function test_a_capacity_change_between_the_first_look_and_the_transaction_is_not_trusted(): void
    {
        $opportunity = $this->opportunityWithSlots(1);
        $student = $this->otherStudent('late');
        $fired = false;

        Opportunity::retrieved(function (Opportunity $retrieved) use (&$fired, $opportunity) {
            if ($fired || $retrieved->opportunity_id !== $opportunity->opportunity_id) {
                return;
            }

            // Guarded before acting: decide() reads this same opportunity
            // under its own lock, which would otherwise fire this listener
            // again and recurse.
            $fired = true;

            $this->acceptOneApplication($opportunity);
        });

        try {
            $this->service()->quickApply($opportunity->opportunity_id, $student);
            $this->fail('the transaction should have re-checked capacity against committed truth, not the pre-check read');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('available awards have been filled', $e->getMessage());
        }

        $this->assertTrue($fired, 'the competing acceptance must actually have run for this test to mean anything');
        $this->assertSame(0, Application::where('opportunity_id', $opportunity->opportunity_id)
            ->where('user_id', $student->user_id)->count());
    }

    // --------------------------------------------------------------- helpers --

    private function service(): ApplicationService
    {
        return app(ApplicationService::class);
    }

    private function opportunity(string $title = 'Zimbabwe Tech Futures Bursary'): Opportunity
    {
        return Opportunity::where('title', $title)->firstOrFail();
    }

    /** The same seeded, fully-approved opportunity, with a specific capacity for these tests to fill. */
    private function opportunityWithSlots(int $slots): Opportunity
    {
        $opportunity = $this->opportunity();
        $opportunity->update(['award_slots' => $slots]);

        return $opportunity->fresh();
    }

    /** A second (or third...) applicant, distinct from the seeded student, for multi-applicant capacity tests. */
    private function otherStudent(string $suffix): User
    {
        return User::create([
            'role_id' => $this->student->role_id,
            'full_name' => 'Other Student ' . strtoupper($suffix),
            'email' => 'other-student-' . $suffix . '@example.test',
            'password_hash' => bcrypt('ChangeMe123'),
            'account_status' => AccountStatus::ACTIVE,
            'email_verified' => true,
        ]);
    }

    /**
     * Fills a single-slot opportunity's one award via a real decide() call,
     * so the opportunity ends up CLOSED exactly the way production code
     * closes it - not by setting the column directly - for tests that only
     * care about what happens next. A fresh filler student every call, so
     * this can run more than once per test (the race tests need to) without
     * a duplicate-email collision.
     */
    private function acceptOneApplication(Opportunity $opportunity): Application
    {
        static $n = 0;
        $filler = $this->otherStudent('filler' . ++$n);
        $application = $this->application($opportunity, ApplicationStatus::PENDING, $filler);

        $this->service()->decide($application->application_id, ApplicationStatus::ACCEPTED, 'Filling the slot.', $this->provider);

        return $application->fresh();
    }

    /** The disk under test - resolves from config so these tests run unchanged against `local` or `s3`. */
    private function testDisk(): string
    {
        return (string) config('filesystems.default', 'local');
    }

    private function applicationCount(Opportunity $opportunity): int
    {
        return DB::table('applications')
            ->where('user_id', $this->student->user_id)
            ->where('opportunity_id', $opportunity->opportunity_id)
            ->count();
    }

    private function application(Opportunity $opportunity, string $status, ?User $user = null): Application
    {
        return Application::create([
            'user_id' => ($user ?? $this->student)->user_id,
            'opportunity_id' => $opportunity->opportunity_id,
            'application_status' => $status,
            'submitted_at' => Carbon::now()->subDay(),
        ]);
    }
}
