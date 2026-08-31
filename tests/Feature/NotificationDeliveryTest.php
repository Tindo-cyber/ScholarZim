<?php

namespace Tests\Feature;

use App\Mail\ScholarZimMail;
use App\Models\Notification;
use App\Models\User;
use App\Services\NotificationService;
use App\Support\NotificationPresentation;
use App\Support\NotificationType;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Notifications, the preferences that gate them, and the queue that carries the
 * email.
 *
 * The pagination cases are the reason this file exists. Filtering used to happen
 * after the page was cut, so the list, its totals and its page links each
 * described a different set of rows - and every one of those numbers looked
 * plausible on its own.
 */
class NotificationDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->student = User::where('email', 'student@scholarzim.co.zw')->firstOrFail();
        Notification::query()->delete();
    }

    private function service(): NotificationService
    {
        return app(NotificationService::class);
    }

    // ------------------------------------------------------------- creation --

    public function test_a_notification_is_written_with_everything_it_needs(): void
    {
        $notification = $this->service()->notifyUser(
            $this->student,
            NotificationType::APPLICATION_ACCEPTED,
            'Your application was approved.',
            '/applications/1/confirmation',
            1
        );

        $this->assertNotNull($notification);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->student->user_id,
            'type' => NotificationType::APPLICATION_ACCEPTED,
            'related_id' => 1,
            'is_read' => false,
        ]);
    }

    /**
     * A failure to record one must not be silent. It cannot be thrown either -
     * these run after the transaction they describe has committed - so it has to
     * leave a durable trace instead of one log line.
     */
    public function test_a_failed_notification_write_is_audited_rather_than_swallowed(): void
    {
        Notification::creating(function () {
            throw new \RuntimeException('notifications table unavailable');
        });

        $result = $this->service()->notifyUser(
            $this->student,
            NotificationType::APPLICATION_ACCEPTED,
            'Your application was approved.'
        );

        $this->assertNull($result);
        $this->assertDatabaseHas('audit_log', [
            'action' => 'NOTIFICATION_DELIVERY_FAILED',
            'actor_email' => $this->student->email,
        ]);
    }

    // ---------------------------------------------------------- preferences --

    /**
     * Preferences gate email, never the notification centre: a student who turns
     * off application email must still be able to see the decision in the app.
     */
    #[DataProvider('preferenceMatrix')]
    public function test_email_follows_the_category_preference(
        string $column,
        string $type,
        bool $enabled,
        bool $expectEmail
    ): void {
        Mail::fake();
        $this->student->update([$column => $enabled]);

        $this->service()->notifyUser($this->student->fresh(), $type, 'A message.');

        $expectEmail
            ? Mail::assertQueued(ScholarZimMail::class)
            : Mail::assertNothingQueued();

        // The in-app row lands either way.
        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->student->user_id,
            'type' => $type,
        ]);
    }

    public static function preferenceMatrix(): array
    {
        return [
            'applications on' => ['email_notify_applications', NotificationType::APPLICATION_ACCEPTED, true, true],
            'applications off' => ['email_notify_applications', NotificationType::APPLICATION_ACCEPTED, false, false],
            'scholarships on' => ['email_notify_scholarships', NotificationType::NEW_OPPORTUNITY, true, true],
            'scholarships off' => ['email_notify_scholarships', NotificationType::NEW_OPPORTUNITY, false, false],
            'system on' => ['email_notify_system', NotificationType::PROFILE_INCOMPLETE, true, true],
            'system off' => ['email_notify_system', NotificationType::PROFILE_INCOMPLETE, false, false],
        ];
    }

    // ------------------------------------------------------------ filtering --

    /** Every declared type belongs to exactly one category, or a filter loses it. */
    public function test_every_notification_type_is_reachable_through_a_category(): void
    {
        $covered = [];

        foreach (NotificationPresentation::CATEGORIES as $category) {
            foreach (NotificationPresentation::typesInCategory($category) as $type) {
                $this->assertArrayNotHasKey($type, $covered, $type . ' is in more than one category');
                $covered[$type] = $category;
            }
        }

        foreach (NotificationType::ALL as $type) {
            $this->assertArrayHasKey($type, $covered, $type . ' is in no category and would vanish from filters');
        }
    }

    public function test_filtering_returns_only_that_categorys_rows(): void
    {
        $this->seedNotifications(applications: 3, scholarships: 2, system: 1);

        $applications = $this->service()
            ->paginateForUser($this->student->user_id, NotificationPresentation::CATEGORY_APPLICATIONS);

        $this->assertSame(3, $applications->total());
        $this->assertCount(3, $applications->items());

        foreach ($applications->items() as $row) {
            $this->assertSame(
                NotificationPresentation::CATEGORY_APPLICATIONS,
                NotificationPresentation::category($row->type)
            );
        }
    }

    /**
     * The bug in its clearest form. Twenty-five scholarship notifications sit in
     * front of five application ones, so filtering after the page was cut left
     * page 1 of the Applications tab completely empty - while its own footer
     * claimed thirty results.
     */
    public function test_a_filtered_first_page_is_not_empty_because_of_ordering(): void
    {
        $this->seedNotifications(applications: 5, scholarships: 25);

        $page = $this->service()->paginateForUser(
            $this->student->user_id,
            NotificationPresentation::CATEGORY_APPLICATIONS,
            20
        );

        $this->assertSame(5, $page->total(), 'the total must describe the filtered set');
        $this->assertCount(5, $page->items(), 'and the page must actually contain them');
        $this->assertSame(1, $page->lastPage());
    }

    public function test_pagination_metadata_describes_the_filtered_set(): void
    {
        $this->seedNotifications(applications: 45, scholarships: 10);

        $page = $this->service()->paginateForUser(
            $this->student->user_id,
            NotificationPresentation::CATEGORY_APPLICATIONS,
            20
        );

        $this->assertSame(45, $page->total());
        $this->assertSame(3, $page->lastPage());
        $this->assertSame(20, $page->perPage());
        $this->assertCount(20, $page->items());

        $last = $this->service()->paginateForUser(
            $this->student->user_id,
            NotificationPresentation::CATEGORY_APPLICATIONS,
            20
        )->setPath('')->url(3);

        $this->assertStringContainsString('page=3', $last);
    }

    /** Every filtered row is reachable by walking the pages, and none twice. */
    public function test_walking_the_pages_yields_each_row_exactly_once(): void
    {
        $this->seedNotifications(applications: 25, scholarships: 25);

        $seen = [];

        for ($page = 1; $page <= 3; $page++) {
            $this->app['request']->merge(['page' => $page]);
            \Illuminate\Pagination\Paginator::currentPageResolver(static fn () => $page);

            foreach ($this->service()->paginateForUser(
                $this->student->user_id,
                NotificationPresentation::CATEGORY_APPLICATIONS,
                10
            )->items() as $row) {
                $seen[] = $row->notification_id;
            }
        }

        \Illuminate\Pagination\Paginator::currentPageResolver(static fn () => 1);

        $this->assertCount(25, $seen);
        $this->assertSame(count($seen), count(array_unique($seen)), 'no row may appear on two pages');
    }

    public function test_counts_honour_the_same_filter_as_the_list(): void
    {
        $this->seedNotifications(applications: 4, scholarships: 6);

        $this->assertSame(10, $this->service()->countForUser($this->student->user_id));
        $this->assertSame(
            4,
            $this->service()->countForUser($this->student->user_id, NotificationPresentation::CATEGORY_APPLICATIONS)
        );
        $this->assertSame(
            6,
            $this->service()->unreadCountForUser(
                $this->student->user_id,
                NotificationPresentation::CATEGORY_SCHOLARSHIPS
            )
        );
    }

    /** A stale bookmark shows everything rather than a convincing empty list. */
    public function test_an_unknown_category_is_ignored_not_matched_against_nothing(): void
    {
        $this->seedNotifications(applications: 2, scholarships: 2);

        $page = $this->service()->paginateForUser($this->student->user_id, 'Nonsense');

        $this->assertSame(4, $page->total());
    }

    public function test_the_notifications_page_renders_a_filtered_view(): void
    {
        $this->seedNotifications(applications: 3, scholarships: 2);

        $this->actingAs($this->student)
            ->get('/notifications?category=' . NotificationPresentation::CATEGORY_APPLICATIONS)
            ->assertOk();
    }

    // --------------------------------------------------- duplicate delivery --

    /**
     * The replay case: a queued job retried after a timeout, or a daily sweep
     * running twice, must not tell the applicant the same thing again.
     */
    public function test_notify_once_is_idempotent_for_the_same_record(): void
    {
        Mail::fake();

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $this->service()->notifyOnce(
                $this->student,
                NotificationType::DEADLINE_REMINDER,
                'A deadline is approaching.',
                '/scholarships/7',
                7
            );
        }

        $this->assertSame(1, Notification::where('type', NotificationType::DEADLINE_REMINDER)->count());
        Mail::assertQueuedCount(1);
    }

    /** Different records are different news, so both are delivered. */
    public function test_notify_once_still_separates_different_records(): void
    {
        $this->service()->notifyOnce($this->student, NotificationType::DEADLINE_REMINDER, 'One.', null, 7);
        $this->service()->notifyOnce($this->student, NotificationType::DEADLINE_REMINDER, 'Two.', null, 8);

        $this->assertSame(2, Notification::where('type', NotificationType::DEADLINE_REMINDER)->count());
    }

    /**
     * The default stays deliberately repeatable. A student re-applying to a
     * listing they withdrew from is a new submission they have to see, so
     * notifyUser() must not quietly become idempotent.
     */
    public function test_the_default_path_still_allows_a_deliberate_repeat(): void
    {
        $this->service()->notifyUser($this->student, NotificationType::APPLICATION_SUBMITTED, 'Application sent.', null, 5);
        $this->service()->notifyUser($this->student, NotificationType::APPLICATION_SUBMITTED, 'Application sent again.', null, 5);

        $this->assertSame(2, Notification::where('type', NotificationType::APPLICATION_SUBMITTED)->count());
    }

    /** The scheduled reminders stay idempotent across repeated runs. */
    public function test_the_reminder_sweep_does_not_duplicate_on_a_second_run(): void
    {
        $this->artisan('scholarzim:deadline-reminders')->assertSuccessful();
        $after = Notification::count();

        $this->artisan('scholarzim:deadline-reminders')->assertSuccessful();

        $this->assertSame($after, Notification::count(), 'a second sweep must add nothing');
    }

    /**
     * The tally a sweep reports must count people actually notified, not people
     * considered. Suppressing a duplicate and still counting it would make a
     * repeat run look like it had done work.
     */
    public function test_a_repeat_sweep_reports_that_it_sent_nothing(): void
    {
        $this->artisan('scholarzim:deadline-reminders')->assertSuccessful();
        $afterFirst = Notification::where('type', NotificationType::DEADLINE_REMINDER)->count();

        $this->artisan('scholarzim:deadline-reminders')
            ->expectsOutputToContain('0 reminder(s) sent')
            ->assertSuccessful();

        $this->assertSame(
            $afterFirst,
            Notification::where('type', NotificationType::DEADLINE_REMINDER)->count()
        );
    }

    // --------------------------------------------------------- queue and mail --

    public function test_mail_is_queued_rather_than_sent_inline(): void
    {
        Mail::fake();

        $this->service()->notifyUser($this->student, NotificationType::APPLICATION_ACCEPTED, 'Approved.');

        Mail::assertQueued(ScholarZimMail::class);
        Mail::assertNothingSent();
    }

    public function test_the_mailable_retries_with_growing_backoff(): void
    {
        $mail = new ScholarZimMail('Subject', 'emails.notification', []);

        $this->assertInstanceOf(ShouldQueue::class, $mail);
        $this->assertSame(3, $mail->tries);
        $this->assertSame([60, 300, 900], $mail->backoff(), 'a fixed minute retries inside the same outage');
    }

    /**
     * The after-commit guarantee, asserted on the mailable rather than inferred
     * from call ordering. With the database queue driver a rolled-back
     * transaction takes the job row with it; on redis or SQS it would not, and
     * an email announcing something that never happened cannot be recalled.
     */
    public function test_mail_is_flagged_to_wait_for_the_transaction_to_commit(): void
    {
        $mail = new ScholarZimMail('Subject', 'emails.notification', []);

        $property = new \ReflectionProperty($mail, 'afterCommit');
        $property->setAccessible(true);

        $this->assertTrue($property->getValue($mail));
    }

    /**
     * And end to end: nothing announced by a transaction that rolled back
     * survives it.
     */
    public function test_a_rolled_back_transaction_leaves_no_notification_behind(): void
    {
        Mail::fake();
        $before = Notification::count();

        try {
            DB::transaction(function () {
                $this->service()->notifyUser($this->student, NotificationType::APPLICATION_ACCEPTED, 'Approved.');

                throw new \RuntimeException('rolled back after notifying');
            });
        } catch (\RuntimeException $e) {
            // expected
        }

        $this->assertSame($before, Notification::count(), 'the in-app row must roll back with its transaction');
    }

    public function test_the_failed_jobs_table_exists_for_permanently_failed_mail(): void
    {
        $this->assertTrue(
            \Illuminate\Support\Facades\Schema::hasTable('failed_jobs'),
            'a permanently failed email must land somewhere it can be retried from'
        );
        $this->assertTrue(method_exists(ScholarZimMail::class, 'failed'));
    }

    // --------------------------------------------------------------- helpers --

    private function seedNotifications(int $applications = 0, int $scholarships = 0, int $system = 0): void
    {
        $plan = [
            NotificationType::APPLICATION_ACCEPTED => $applications,
            NotificationType::NEW_OPPORTUNITY => $scholarships,
            NotificationType::PROFILE_INCOMPLETE => $system,
        ];

        // Scholarship rows are written last so they are newest, which is what put
        // them in front of the application rows on an unfiltered first page.
        $order = 0;

        foreach ([NotificationType::APPLICATION_ACCEPTED, NotificationType::PROFILE_INCOMPLETE, NotificationType::NEW_OPPORTUNITY] as $type) {
            for ($i = 0; $i < $plan[$type]; $i++) {
                Notification::create([
                    'user_id' => $this->student->user_id,
                    'type' => $type,
                    'message' => $type . ' #' . $i,
                    'is_read' => false,
                    'created_at' => Carbon::now()->addSeconds($order++),
                ]);
            }
        }
    }
}
