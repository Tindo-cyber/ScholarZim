<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Opportunity;
use App\Models\User;
use App\Services\RecommendationService;
use App\Support\AccountStatus;
use App\Support\ApplicationStatus;
use App\Support\AuditAction;
use App\Support\NotificationType;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The award: the step after approval, where the scholarship is actually granted.
 *
 * Two rules carry the weight here. Only the owning provider may grant it, and a
 * student who holds one can never apply for that scholarship again - through the
 * wizard, through quick apply, or by posting at the endpoint directly.
 */
class ApplicationAwardTest extends TestCase
{
    use RefreshDatabase;

    private User $student;

    private User $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->student = User::where('email', 'student@scholarzim.co.zw')->firstOrFail();
        $this->provider = User::where('email', 'provider@scholarzim.co.zw')->firstOrFail();
    }

    // ------------------------------------------------------------ the action --

    public function test_a_provider_can_award_an_approved_application(): void
    {
        $application = $this->approvedApplication();

        $this->actingAs($this->provider)
            ->post($this->awardUrl($application))
            ->assertRedirect('/provider/applications/' . $application->application_id);

        $this->assertSame(ApplicationStatus::AWARDED, $application->fresh()->application_status);
    }

    public function test_awarding_stamps_the_date_it_was_granted(): void
    {
        $application = $this->approvedApplication();

        $this->assertNull($application->awarded_at);

        $this->actingAs($this->provider)->post($this->awardUrl($application));

        $awardedAt = $application->fresh()->awarded_at;

        $this->assertNotNull($awardedAt);
        $this->assertTrue($awardedAt->isToday());
    }

    /**
     * The same application record moves on; it is never replaced. A second row
     * would split one student's history across two applications and break the
     * (user_id, opportunity_id) uniqueness the whole lifecycle rests on.
     */
    public function test_awarding_moves_the_existing_application_and_keeps_its_history(): void
    {
        $application = $this->approvedApplication();
        $application->update(['personal_statement' => 'My statement, written at submission time.']);

        $this->actingAs($this->provider)->post($this->awardUrl($application));

        $this->assertSame(1, $this->applicationCount($application->opportunity_id));

        $fresh = $application->fresh();
        $this->assertSame($application->application_id, $fresh->application_id);
        $this->assertSame('My statement, written at submission time.', $fresh->personal_statement);
        $this->assertNotNull($fresh->submitted_at);
    }

    // ----------------------------------------------------------- who may act --

    public function test_another_provider_cannot_award_someone_elses_applicant(): void
    {
        $application = $this->approvedApplication();

        $this->actingAs($this->foreignProvider())
            ->post($this->awardUrl($application))
            ->assertForbidden();

        $this->assertSame(ApplicationStatus::APPROVED, $application->fresh()->application_status);
    }

    public function test_an_applicant_cannot_award_their_own_application(): void
    {
        $application = $this->approvedApplication();

        $this->actingAs($this->student)
            ->post($this->awardUrl($application))
            ->assertForbidden();

        $this->assertSame(ApplicationStatus::APPROVED, $application->fresh()->application_status);
    }

    public function test_a_guest_cannot_award_an_application(): void
    {
        $application = $this->approvedApplication();

        $this->post($this->awardUrl($application))->assertRedirect('/login');

        $this->assertSame(ApplicationStatus::APPROVED, $application->fresh()->application_status);
    }

    // ------------------------------------------------------------ transitions --

    public function test_an_application_that_was_never_approved_cannot_be_awarded(): void
    {
        $application = $this->application();

        $this->actingAs($this->provider)
            ->post($this->awardUrl($application))
            ->assertRedirect();

        $this->assertSame(ApplicationStatus::SUBMITTED, $application->fresh()->application_status);
        $this->assertNull($application->fresh()->awarded_at);
    }

    /** Every backward move, refused by the state machine rather than by the view. */
    public function test_an_awarded_application_cannot_be_moved_back_into_review(): void
    {
        $application = $this->awardedApplication();
        $grantedAt = $application->fresh()->awarded_at;

        foreach ([
            ApplicationStatus::UNDER_REVIEW,
            ApplicationStatus::SHORTLISTED,
            ApplicationStatus::INTERVIEW,
            ApplicationStatus::APPROVED,
            ApplicationStatus::WAITLISTED,
            ApplicationStatus::REJECTED,
        ] as $status) {
            $this->actingAs($this->provider)
                ->post('/provider/applications/' . $application->application_id . '/review', [
                    'status' => $status,
                    'reason' => 'Reconsidering.',
                ])
                ->assertRedirect();

            $this->assertSame(
                ApplicationStatus::AWARDED,
                $application->fresh()->application_status,
                'an awarded application must not be movable to ' . $status
            );
        }

        // And the grant date is untouched by any of it.
        $this->assertEquals($grantedAt, $application->fresh()->awarded_at);
    }

    public function test_an_awarded_application_cannot_be_withdrawn(): void
    {
        $application = $this->awardedApplication();

        $this->actingAs($this->student)
            ->post('/applications/' . $application->application_id . '/withdraw')
            ->assertRedirect();

        $this->assertSame(ApplicationStatus::AWARDED, $application->fresh()->application_status);
    }

    /**
     * Two clicks on the same button. The row lock serialises them and the second
     * reads a status nothing moves out of, so there is one award, one timestamp,
     * one audit line and one notification.
     */
    public function test_awarding_twice_grants_one_award(): void
    {
        $application = $this->approvedApplication();

        $this->actingAs($this->provider)->post($this->awardUrl($application));
        $firstStamp = $application->fresh()->awarded_at;

        Carbon::setTestNow(Carbon::now()->addHour());
        $this->actingAs($this->provider)->post($this->awardUrl($application))->assertRedirect();
        Carbon::setTestNow();

        $this->assertEquals($firstStamp, $application->fresh()->awarded_at);

        $this->assertSame(1, Application::where('application_id', $application->application_id)
            ->where('application_status', ApplicationStatus::AWARDED)
            ->count());

        $this->assertSame(1, \App\Models\Notification::where('user_id', $this->student->user_id)
            ->where('type', NotificationType::APPLICATION_AWARDED)
            ->count());

        $this->assertSame(1, \DB::table('audit_log')
            ->where('action', AuditAction::AWARD_APPLICATION)
            ->where('entity_id', $application->application_id)
            ->count());
    }

    // ------------------------------------------------- notification and audit --

    public function test_the_student_is_notified_that_they_were_awarded(): void
    {
        $application = $this->approvedApplication();

        $this->actingAs($this->provider)->post($this->awardUrl($application));

        $notification = \App\Models\Notification::where('user_id', $this->student->user_id)
            ->where('type', NotificationType::APPLICATION_AWARDED)
            ->firstOrFail();

        $this->assertStringContainsString('Congratulations', $notification->message);
        $this->assertStringContainsString(
            $application->opportunity->title,
            $notification->message
        );
        $this->assertSame(
            '/applications/' . $application->application_id . '/confirmation',
            $notification->link
        );
    }

    /** Email follows the preference; the in-app notification is written either way. */
    public function test_award_email_respects_the_students_notification_preference(): void
    {
        Mail::fake();

        $this->student->update(['email_notify_applications' => false]);
        $application = $this->approvedApplication();

        $this->actingAs($this->provider)->post($this->awardUrl($application));

        // Queued, not sent: ScholarZimMail is a queued mailable.
        Mail::assertNothingQueued();

        // The preference gates email only - the notification centre still has it.
        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->student->user_id,
            'type' => NotificationType::APPLICATION_AWARDED,
        ]);
    }

    public function test_award_email_is_sent_when_the_student_wants_application_email(): void
    {
        Mail::fake();

        $this->student->update(['email_notify_applications' => true]);
        $application = $this->approvedApplication();

        $this->actingAs($this->provider)->post($this->awardUrl($application));

        Mail::assertQueued(
            \App\Mail\ScholarZimMail::class,
            fn ($mail) => $mail->hasTo($this->student->email)
        );
        Mail::assertQueuedCount(1);
    }

    public function test_the_award_is_audited_with_everything_needed_to_identify_it(): void
    {
        $application = $this->approvedApplication();

        $this->actingAs($this->provider)->post($this->awardUrl($application));

        $entry = \DB::table('audit_log')
            ->where('action', AuditAction::AWARD_APPLICATION)
            ->where('entity_id', $application->application_id)
            ->first();

        $this->assertNotNull($entry);

        // Who awarded it.
        $this->assertSame($this->provider->email, $entry->actor_email);
        $this->assertSame($this->provider->user_id, (int) $entry->actor_user_id);

        // What was awarded, and to whom.
        $this->assertSame('APPLICATION', $entry->entity_type);
        $newValues = json_decode((string) $entry->new_values, true);
        $this->assertSame($this->student->user_id, $newValues['applicant_user_id']);
        $this->assertSame($this->student->email, $newValues['applicant_email']);
        $this->assertSame($application->opportunity_id, $newValues['opportunity_id']);
        $this->assertSame($application->opportunity->title, $newValues['opportunity_title']);
        $this->assertSame(ApplicationStatus::AWARDED, $newValues['application_status']);

        // The status it replaced, and when.
        $old = json_decode((string) $entry->old_values, true);
        $this->assertSame(ApplicationStatus::APPROVED, $old['application_status']);
        $this->assertNotNull($entry->created_at);

        // Nothing sensitive rode along.
        $serialised = strtolower((string) $entry->new_values . (string) $entry->old_values);
        $this->assertStringNotContainsString('password', $serialised);
        $this->assertStringNotContainsString('token', $serialised);
    }

    // ---------------------------------------------------- blocking a re-apply --

    public function test_an_awarded_student_cannot_open_the_apply_wizard_again(): void
    {
        $application = $this->awardedApplication();

        $this->actingAs($this->student)
            ->get('/apply/' . $application->opportunity_id)
            ->assertRedirect('/my-applications');
    }

    public function test_an_awarded_student_cannot_post_a_second_application(): void
    {
        $application = $this->awardedApplication();

        $this->actingAs($this->student)
            ->post('/apply/' . $application->opportunity_id, [
                'personal_statement' => str_repeat('I would like to be considered again. ', 6),
                'confirm' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHas(
                'errorMessage',
                'You have already been awarded this scholarship and cannot apply again.'
            );

        $this->assertSame(ApplicationStatus::AWARDED, $application->fresh()->application_status);
        $this->assertSame(1, $this->applicationCount($application->opportunity_id));
    }

    public function test_an_awarded_student_cannot_quick_apply_again(): void
    {
        $application = $this->awardedApplication();

        $this->actingAs($this->student)
            ->post('/apply/' . $application->opportunity_id . '/quick')
            ->assertRedirect()
            ->assertSessionHas(
                'errorMessage',
                'You have already been awarded this scholarship and cannot apply again.'
            );

        $this->assertSame(ApplicationStatus::AWARDED, $application->fresh()->application_status);
        $this->assertSame(1, $this->applicationCount($application->opportunity_id));
    }

    /** The rules that must survive the new one: neither outcome locks the listing. */
    public function test_a_rejected_application_can_still_be_submitted_again(): void
    {
        $application = $this->application();
        $application->update(['application_status' => ApplicationStatus::REJECTED]);

        $this->actingAs($this->student)
            ->get('/apply/' . $application->opportunity_id)
            ->assertOk();

        $this->actingAs($this->student)
            ->post('/apply/' . $application->opportunity_id . '/quick')
            ->assertRedirect();

        $this->assertSame(ApplicationStatus::SUBMITTED, $application->fresh()->application_status);
    }

    public function test_a_withdrawn_application_can_still_be_submitted_again(): void
    {
        $application = $this->application();
        $application->update([
            'application_status' => ApplicationStatus::WITHDRAWN,
            'withdrawn_at' => Carbon::now(),
        ]);

        $this->actingAs($this->student)
            ->post('/apply/' . $application->opportunity_id . '/quick')
            ->assertRedirect();

        $this->assertSame(ApplicationStatus::SUBMITTED, $application->fresh()->application_status);
    }

    /** One student's award says nothing about anybody else's chances. */
    public function test_another_student_can_still_apply_to_an_awarded_scholarship(): void
    {
        $application = $this->awardedApplication();
        $other = $this->secondStudent();

        $this->actingAs($other)
            ->post('/apply/' . $application->opportunity_id . '/quick')
            ->assertRedirect();

        $this->assertDatabaseHas('applications', [
            'user_id' => $other->user_id,
            'opportunity_id' => $application->opportunity_id,
            'application_status' => ApplicationStatus::SUBMITTED,
        ]);
    }

    /** The student keeps the award and everything they submitted for it. */
    public function test_an_awarded_student_can_still_view_their_application(): void
    {
        $application = $this->awardedApplication();

        $this->actingAs($this->student)
            ->get('/applications/' . $application->application_id . '/confirmation')
            ->assertOk()
            ->assertSee('Scholarship awarded');
    }

    /** And the listing itself stays readable - it is theirs now. */
    public function test_the_scholarship_page_offers_the_award_instead_of_apply(): void
    {
        $application = $this->awardedApplication();

        $response = $this->actingAs($this->student)
            ->get('/scholarships/' . $application->opportunity_id)
            ->assertOk()
            ->assertSee('Scholarship awarded');

        $this->assertStringNotContainsString(
            'Apply now',
            $response->getContent()
        );
    }

    // ------------------------------------------------------ recommendations --

    public function test_an_awarded_scholarship_is_not_recommended_to_that_student(): void
    {
        $opportunity = $this->opportunity();

        $recommendations = app(RecommendationService::class);

        // It is a live, eligible listing before the award.
        $before = array_column($recommendations->rankedIdsForUser($this->student->fresh()), 'id');
        $this->assertContains($opportunity->opportunity_id, $before);

        $this->awardedApplication();

        $after = array_column($recommendations->rankedIdsForUser($this->student->fresh()), 'id');
        $this->assertNotContains($opportunity->opportunity_id, $after);
    }

    // ------------------------------------------------ the provider's own view --

    public function test_the_provider_inbox_offers_an_awarded_filter(): void
    {
        $this->awardedApplication();

        $this->actingAs($this->provider)
            ->get('/provider/applications')
            ->assertOk()
            ->assertSee('status=AWARDED', false);
    }

    public function test_the_awarded_filter_lists_the_awarded_student_and_the_date(): void
    {
        $application = $this->awardedApplication();

        $this->actingAs($this->provider)
            ->get('/provider/applications?status=' . ApplicationStatus::AWARDED)
            ->assertOk()
            ->assertSee($this->student->displayName())
            ->assertSee($application->opportunity->title)
            ->assertSee('Awarded ' . $application->fresh()->awarded_at->format('d M Y'));
    }

    /** Cross-tenant isolation on the awarded list specifically. */
    public function test_a_provider_never_sees_another_providers_awarded_students(): void
    {
        $this->awardedApplication();

        $this->actingAs($this->foreignProvider())
            ->get('/provider/applications?status=' . ApplicationStatus::AWARDED)
            ->assertOk()
            ->assertDontSee($this->student->displayName());
    }

    /**
     * Its own count, and not APPROVED's. An awarded application has left the
     * approved bucket, so the two numbers cannot both claim it.
     */
    public function test_awarded_has_its_own_count_and_is_not_counted_as_approved(): void
    {
        $awarded = $this->awardedApplication();
        $approved = $this->application('Midlands Engineering Excellence Award', $this->secondStudent());
        $approved->update(['application_status' => ApplicationStatus::APPROVED]);

        $counts = app(\App\Services\ApplicationService::class)
            ->statusCountsForProvider($this->provider);

        $this->assertSame(1, $counts[ApplicationStatus::AWARDED] ?? 0);
        $this->assertSame(1, $counts[ApplicationStatus::APPROVED] ?? 0);
        // The seeded application is still under review, so the three buckets
        // account for every row exactly once - AWARDED is not also APPROVED.
        $this->assertSame(1, $counts[ApplicationStatus::UNDER_REVIEW] ?? 0);
        $this->assertSame(3, array_sum($counts));
        $this->assertSame(ApplicationStatus::AWARDED, $awarded->fresh()->application_status);
    }

    public function test_provider_analytics_separates_approved_from_awarded(): void
    {
        $this->awardedApplication();
        $approved = $this->application('Midlands Engineering Excellence Award', $this->secondStudent());
        $approved->update(['application_status' => ApplicationStatus::APPROVED]);

        $overview = app(\App\Services\ProviderAnalyticsService::class)->overview($this->provider);

        $funnel = collect($overview['funnel'])->keyBy('label');

        // Two here plus the seeded one still under review.
        $this->assertSame(3, $funnel['Applications']['value']);
        // Cumulative by construction: both reached approval, one went past it.
        $this->assertSame(2, $funnel['Approved']['value']);
        $this->assertSame(1, $funnel['Awarded']['value']);

        // The status mix is not cumulative - each application appears once.
        $this->assertSame(1, $overview['statusCounts'][ApplicationStatus::APPROVED] ?? 0);
        $this->assertSame(1, $overview['statusCounts'][ApplicationStatus::AWARDED] ?? 0);
    }

    // ------------------------------------------ existing workflows still work --

    public function test_the_ordinary_review_path_is_unchanged(): void
    {
        $application = $this->application();

        foreach ([
            [ApplicationStatus::UNDER_REVIEW, 'Reading it now.'],
            [ApplicationStatus::SHORTLISTED, 'Strong candidate.'],
            [ApplicationStatus::APPROVED, 'Offered a full bursary.'],
        ] as [$status, $reason]) {
            $this->actingAs($this->provider)
                ->post('/provider/applications/' . $application->application_id . '/review', [
                    'status' => $status,
                    'reason' => $reason,
                ])
                ->assertRedirect();

            $this->assertSame($status, $application->fresh()->application_status);
        }

        // Approved but not awarded: no stamp until the award is granted.
        $this->assertNull($application->fresh()->awarded_at);

        $this->actingAs($this->provider)->post($this->awardUrl($application));

        $this->assertSame(ApplicationStatus::AWARDED, $application->fresh()->application_status);
    }

    /** Awarding is not a review decision, so the review endpoint cannot reach it. */
    public function test_the_review_endpoint_refuses_awarded_as_a_status(): void
    {
        $application = $this->approvedApplication();

        $this->actingAs($this->provider)
            ->post('/provider/applications/' . $application->application_id . '/review', [
                'status' => ApplicationStatus::AWARDED,
                'reason' => 'Trying the back door.',
            ])
            ->assertSessionHasErrors('status');

        $this->assertSame(ApplicationStatus::APPROVED, $application->fresh()->application_status);
    }

    /** Nor can the bulk action, which would award a whole page in one click. */
    public function test_the_bulk_action_cannot_award(): void
    {
        $application = $this->approvedApplication();

        $this->actingAs($this->provider)
            ->post('/provider/applications/bulk', [
                'applications' => [$application->application_id],
                'status' => ApplicationStatus::AWARDED,
                'reason' => 'Trying the back door.',
            ])
            ->assertSessionHasErrors('status');

        $this->assertSame(ApplicationStatus::APPROVED, $application->fresh()->application_status);
    }

    // ----------------------------------------------------------------- setup --

    private function awardUrl(Application $application): string
    {
        return '/provider/applications/' . $application->application_id . '/award';
    }

    /**
     * How many applications this student holds against one listing.
     *
     * Scoped to the listing rather than the student because the seeder gives
     * them an unrelated application elsewhere, and the rule being checked is
     * "one row per (student, listing)", not "one row per student".
     */
    private function applicationCount(int $opportunityId): int
    {
        return Application::where('user_id', $this->student->user_id)
            ->where('opportunity_id', $opportunityId)
            ->count();
    }

    private function opportunity(?string $title = null): Opportunity
    {
        return Opportunity::where('title', $title ?? 'Zimbabwe Tech Futures Undergraduate Bursary')
            ->firstOrFail();
    }

    private function application(?string $title = null, ?User $user = null): Application
    {
        return Application::create([
            'user_id' => ($user ?? $this->student)->user_id,
            'opportunity_id' => $this->opportunity($title)->opportunity_id,
            'application_status' => ApplicationStatus::SUBMITTED,
            'submitted_at' => Carbon::now(),
        ]);
    }

    private function approvedApplication(): Application
    {
        $application = $this->application();
        $application->update(['application_status' => ApplicationStatus::APPROVED]);

        return $application;
    }

    /** Awarded through the real action, so the stamp and the trail are real too. */
    private function awardedApplication(): Application
    {
        $application = $this->approvedApplication();

        $this->actingAs($this->provider)->post($this->awardUrl($application));
        $this->flushSession();

        return $application->fresh();
    }

    private function secondStudent(): User
    {
        return User::firstOrCreate(
            ['email' => 'second-student@example.test'],
            [
                'role_id' => $this->student->role_id,
                'full_name' => 'Rudo Chikafu',
                'password_hash' => bcrypt('ChangeMe123'),
                'account_status' => AccountStatus::ACTIVE,
                'email_verified' => true,
            ]
        );
    }

    private function foreignProvider(): User
    {
        return User::firstOrCreate(
            ['email' => 'other-provider@example.test'],
            [
                'role_id' => $this->provider->role_id,
                'full_name' => 'Other Provider',
                'password_hash' => bcrypt('ChangeMe123'),
                'account_status' => AccountStatus::ACTIVE,
                'email_verified' => true,
            ]
        );
    }
}
