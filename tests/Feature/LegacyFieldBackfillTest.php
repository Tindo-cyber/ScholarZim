<?php

namespace Tests\Feature;

use App\Models\ApplicantProfile;
use App\Models\ApplicantProgramme;
use App\Models\Field;
use App\Models\LegacyFieldAlias;
use App\Models\Opportunity;
use App\Models\OpportunityScope;
use App\Models\Programme;
use App\Models\User;
use App\Services\Catalogue\LegacyFieldBackfill;
use App\Services\Catalogue\LegacyFieldMap;
use App\Services\ProfileDataQuality;
use App\Support\EducationLevel;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Moving the old free-text field of study onto the catalogue.
 *
 * The old columns are kept exactly as they are. Listings whose old field means something the
 * catalogue knows get "Open to" rows (marked so they can be undone and so they are never confused
 * with a provider's own choice); values nothing knows go to an administrator, who says once what
 * they mean. Students cannot be moved automatically - an old field is a field, not a programme - so
 * they are asked to choose their programme, and their old field stands in for a field rule meanwhile.
 */
class LegacyFieldBackfillTest extends TestCase
{
    use RefreshDatabase;

    private LegacyFieldBackfill $backfill;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->backfill = app(LegacyFieldBackfill::class);

        // A clean slate: the demo listings carry old fields of their own.
        Opportunity::query()->update(['target_field' => null]);
        ApplicantProfile::query()->update(['field_of_study' => null]);
    }

    private function listing(?string $targetField, string $title = 'Old Field Award'): Opportunity
    {
        return Opportunity::create([
            'provider_user_id' => User::where('email', 'provider@scholarzim.co.zw')->value('user_id'),
            'provider_name' => 'P', 'title' => $title, 'description' => 'x',
            'education_level' => EducationLevel::UNDERGRADUATE, 'target_field' => $targetField,
            'status' => 'ACTIVE', 'moderation_status' => 'APPROVED',
        ]);
    }

    private function codes(Opportunity $listing): array
    {
        return $listing->scopes()->with('field')->get()->pluck('field.code')->filter()->sort()->values()->all();
    }

    // ------------------------------------------------------------- listings --

    public function test_engineering_becomes_the_engineering_and_construction_engineering_fields(): void
    {
        $listing = $this->listing('Engineering');

        $report = $this->backfill->run();

        $this->assertSame(['071', '073'], $this->codes($listing));
        $this->assertSame(1, $report->listingsMigrated);
        $this->assertSame('Engineering', $listing->fresh()->target_field, 'the old column is never touched');
    }

    public function test_spelling_and_spacing_do_not_matter(): void
    {
        $listing = $this->listing('  computer science and it ');

        $this->backfill->run();

        $this->assertSame(['061'], $this->codes($listing));
    }

    public function test_a_field_that_covers_several_narrow_fields_makes_a_row_for_each(): void
    {
        $listing = $this->listing('Natural Sciences');

        $this->backfill->run();

        $this->assertSame(['051', '053', '054'], $this->codes($listing));
    }

    public function test_the_rows_are_marked_as_made_by_the_migration(): void
    {
        $listing = $this->listing('Law');
        $this->backfill->run();

        $this->assertSame(['legacy_field'], $listing->scopes()->pluck('source')->unique()->all());
    }

    public function test_running_it_again_changes_nothing(): void
    {
        $listing = $this->listing('Engineering');
        $this->backfill->run();

        $again = $this->backfill->run();

        $this->assertSame(0, $again->listingsMigrated);
        $this->assertCount(2, $listing->scopes);
    }

    public function test_a_listing_the_provider_already_scoped_is_left_alone(): void
    {
        $listing = $this->listing('Engineering');
        OpportunityScope::create(['opportunity_id' => $listing->opportunity_id, 'field_id' => Field::where('code', '042')->value('id')]);

        $this->backfill->run();

        $this->assertSame(['042'], $this->codes($listing));
        $this->assertNull($listing->scopes()->value('source'));
    }

    public function test_a_listing_with_no_old_field_gets_nothing(): void
    {
        $listing = $this->listing(null);

        $this->backfill->run();

        $this->assertCount(0, $listing->scopes);
    }

    public function test_a_school_label_is_left_as_it_is(): void
    {
        $listing = $this->listing('General Primary');

        $report = $this->backfill->run();

        $this->assertCount(0, $listing->scopes);
        $this->assertNotContains('general primary', array_column($report->unmapped, 'key'), 'it means nothing to the catalogue on purpose; it is not a problem to fix');
    }

    public function test_a_dry_run_reports_and_writes_nothing(): void
    {
        $listing = $this->listing('Engineering');

        $report = $this->backfill->run(dryRun: true);

        $this->assertSame(1, $report->listingsMigrated);
        $this->assertCount(0, $listing->scopes);
    }

    public function test_undo_removes_only_what_the_migration_made(): void
    {
        $migrated = $this->listing('Engineering');
        $chosen = $this->listing(null, 'Chosen');
        OpportunityScope::create(['opportunity_id' => $chosen->opportunity_id, 'field_id' => Field::where('code', '042')->value('id')]);
        $this->backfill->run();

        $removed = $this->backfill->undo();

        $this->assertSame(2, $removed);
        $this->assertCount(0, $migrated->scopes()->get());
        $this->assertCount(1, $chosen->scopes()->get());
    }

    // ----------------------------------------------------- what could not be mapped --

    public function test_values_nothing_knows_are_listed_with_how_many_listings_and_students_wrote_them(): void
    {
        $this->listing('Underwater Basketry');
        $this->listing('underwater  basketry', 'Second');
        ApplicantProfile::query()->limit(3)->get()->each(fn ($p) => $p->forceFill(['education_level' => EducationLevel::UNDERGRADUATE, 'field_of_study' => 'Underwater Basketry'])->save());

        $report = $this->backfill->run();

        $row = collect($report->unmapped)->firstWhere('key', 'underwater basketry');
        $this->assertNotNull($row);
        $this->assertSame(2, $row['listings']);
        $this->assertSame(3, $row['students']);
        $this->assertCount(0, Opportunity::where('title', 'Old Field Award')->first()->scopes);
    }

    public function test_an_administrator_can_say_what_an_old_value_means_once_and_it_settles_everything(): void
    {
        $a = $this->listing('Underwater Basketry');
        $b = $this->listing(' underwater basketry ', 'Second');
        $admin = User::where('email', 'admin@scholarzim.co.zw')->firstOrFail();

        $this->actingAs($admin)->post('/admin/catalogue/old-values', [
            'value' => 'Underwater Basketry', 'field_id' => Field::where('code', '081')->value('id'),
        ])->assertSessionHasNoErrors();

        $this->assertSame(['081'], $this->codes($a->fresh()), 'listings that wrote it are migrated straight away');
        $this->assertSame(['081'], $this->codes($b->fresh()));
        $this->assertSame(['081'], LegacyFieldMap::codesFor('UNDERWATER BASKETRY'));
        $this->assertDatabaseHas('audit_log', ['action' => 'CATALOGUE_CHANGED', 'actor_email' => $admin->email]);
    }

    public function test_the_page_lists_the_unmapped_values_and_only_an_administrator_sees_it(): void
    {
        $this->listing('Underwater Basketry');
        $admin = User::where('email', 'admin@scholarzim.co.zw')->firstOrFail();

        $this->actingAs($admin)->get('/admin/catalogue/old-values')->assertOk()->assertSee('Underwater Basketry');

        $this->flushSession();
        $this->app['auth']->forgetGuards();
        $this->actingAs(User::where('email', 'provider@scholarzim.co.zw')->firstOrFail())->get('/admin/catalogue/old-values')->assertForbidden();
    }

    public function test_an_alias_can_be_removed_and_the_value_is_unmapped_again(): void
    {
        $admin = User::where('email', 'admin@scholarzim.co.zw')->firstOrFail();
        $this->actingAs($admin)->post('/admin/catalogue/old-values', ['value' => 'Underwater Basketry', 'field_id' => Field::where('code', '081')->value('id')]);
        $alias = LegacyFieldAlias::firstOrFail();

        $this->actingAs($admin)->post('/admin/catalogue/old-values/' . $alias->id . '/delete')->assertSessionHasNoErrors();

        $this->assertSame([], LegacyFieldMap::codesFor('Underwater Basketry'));
    }

    public function test_an_alias_needs_a_value_a_real_field_and_must_not_repeat_a_known_value(): void
    {
        $admin = User::where('email', 'admin@scholarzim.co.zw')->firstOrFail();
        $field = Field::where('code', '081')->value('id');

        $this->actingAs($admin)->post('/admin/catalogue/old-values', ['value' => '', 'field_id' => $field])->assertSessionHasErrors('value');
        $this->actingAs($admin)->post('/admin/catalogue/old-values', ['value' => 'Odd', 'field_id' => 999999])->assertSessionHasErrors('field_id');
        $this->actingAs($admin)->post('/admin/catalogue/old-values', ['value' => 'Engineering', 'field_id' => $field])->assertSessionHasErrors('value');
    }

    // ------------------------------------------------------- the artisan command --

    public function test_the_command_migrates_and_can_be_undone(): void
    {
        $listing = $this->listing('Law');

        $this->artisan('catalogue:migrate-fields', ['--dry-run' => true])->assertSuccessful();
        $this->assertCount(0, $listing->scopes()->get());

        $this->artisan('catalogue:migrate-fields')->assertSuccessful();
        $this->assertSame(['042'], $this->codes($listing));

        $this->artisan('catalogue:migrate-fields', ['--undo' => true])->assertSuccessful();
        $this->assertCount(0, $listing->scopes()->get());
    }

    // ---------------------------------------------------------------- students --

    private function student(): ApplicantProfile
    {
        $user = User::where('email', 'tanaka.chirwa@scholarzim.co.zw')->firstOrFail();
        $profile = ApplicantProfile::where('user_id', $user->user_id)->firstOrFail();
        $profile->forceFill(['education_level' => EducationLevel::UNDERGRADUATE, 'field_of_study' => 'Computer Science & IT'])->save();
        ApplicantProgramme::query()->delete();

        return $profile->fresh();
    }

    public function test_an_enrolled_student_with_an_old_field_and_no_programme_is_asked_to_choose_one(): void
    {
        $profile = $this->student();

        $this->assertTrue(ProfileDataQuality::programmeNeedsChoosing($profile));

        foreach (['/applicant/dashboard', '/applicant/profile'] as $page) {
            $this->actingAs($profile->user)->get($page)->assertOk()
                ->assertSee('choose-programme-prompt', false)
                ->assertSee('Computer Science &amp; IT', false);
        }
    }

    public function test_choosing_a_programme_ends_the_prompt(): void
    {
        $profile = $this->student();
        ApplicantProgramme::create(['profile_id' => $profile->profile_id, 'programme_id' => Programme::where('name', 'BSc Computer Science')->value('id'), 'kind' => 'current']);

        $this->assertFalse(ProfileDataQuality::programmeNeedsChoosing($profile->fresh()));
        $this->actingAs($profile->user)->get('/applicant/dashboard')->assertDontSee('choose-programme-prompt', false);
    }

    public function test_school_leavers_and_primary_pupils_are_not_asked(): void
    {
        $profile = $this->student();

        foreach ([EducationLevel::A_LEVEL, EducationLevel::O_LEVEL, EducationLevel::PRIMARY] as $level) {
            $profile->forceFill(['education_level' => $level])->save();
            $this->assertFalse(ProfileDataQuality::programmeNeedsChoosing($profile->fresh()), $level);
        }
    }

    public function test_a_student_with_no_old_field_and_no_programme_is_still_asked_in_plain_words(): void
    {
        $profile = $this->student();
        $profile->forceFill(['field_of_study' => null])->save();

        $this->assertTrue(ProfileDataQuality::programmeNeedsChoosing($profile->fresh()));
    }

    public function test_the_old_field_stands_in_for_a_field_rule_until_a_programme_is_chosen(): void
    {
        // The detail of this is tested in ProgrammeScopeEligibilityTest; here, end to end through the backfill.
        $profile = $this->student();
        $listing = $this->listing('Computer Science & IT');
        $this->backfill->run();

        $fit = app(\App\Services\RecommendationService::class)->evaluateOne($profile->user, $listing->fresh());

        $this->assertTrue($fit->meetsRequirements());
    }
}
