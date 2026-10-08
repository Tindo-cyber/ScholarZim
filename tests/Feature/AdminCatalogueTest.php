<?php

namespace Tests\Feature;

use App\Models\Field;
use App\Models\Institution;
use App\Models\Programme;
use App\Models\User;
use App\Services\Catalogue\ProgrammeCatalogue;
use App\Support\EducationLevel;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/** The administrator's screens for the programme catalogue. */
class AdminCatalogueTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->admin = User::where('email', 'admin@scholarzim.co.zw')->firstOrFail();
    }

    private function asAdmin()
    {
        return $this->actingAs($this->admin);
    }

    // ------------------------------------------------------------------ access --

    public function test_only_an_administrator_can_reach_any_catalogue_screen(): void
    {
        $provider = User::where('email', 'provider@scholarzim.co.zw')->firstOrFail();
        $student = User::where('email', 'tanaka.chirwa@scholarzim.co.zw')->firstOrFail();

        foreach (['', '/programmes', '/programmes/create', '/fields', '/institutions', '/pending', '/export/programmes.csv'] as $path) {
            $this->flushSession();
            $this->app['auth']->forgetGuards();
            $this->get('/admin/catalogue' . $path)->assertRedirect('/login');

            foreach ([$provider, $student] as $user) {
                // One browser, one role at a time (the app signs the other out), so start clean.
                $this->flushSession();
                $this->app['auth']->forgetGuards();
                $this->actingAs($user)->get('/admin/catalogue' . $path)->assertForbidden();
            }
        }

        $this->flushSession();
        $this->app['auth']->forgetGuards();
        $this->actingAs($student)->post('/admin/catalogue/import')->assertForbidden();

        $this->flushSession();
        $this->app['auth']->forgetGuards();
        $this->actingAs($provider)->post('/admin/catalogue/programmes', ['name' => 'x'])->assertForbidden();
    }

    public function test_the_overview_counts_the_catalogue_and_links_to_the_queue(): void
    {
        app(ProgrammeCatalogue::class)->suggest($this->admin, 'Odd Studies', EducationLevel::DIPLOMA, Field::where('code', '061')->value('id'));

        $this->asAdmin()->get('/admin/catalogue')->assertOk()
            ->assertSee('Programme catalogue')
            ->assertSee('1 waiting')
            ->assertSee('starter list', false);
    }

    // -------------------------------------------------------------- programmes --

    public function test_the_programme_list_can_be_searched_and_filtered(): void
    {
        $html = $this->asAdmin()->get('/admin/catalogue/programmes?q=accounting&level=DIPLOMA')->assertOk()->getContent();

        $this->assertStringContainsString('Diploma in Accountancy', $html);
        $this->assertStringNotContainsString('BCom Accounting', $html);
    }

    public function test_a_programme_can_be_added_with_synonyms_and_institutions(): void
    {
        $this->asAdmin()->post('/admin/catalogue/programmes', [
            'name' => 'BSc Astro Engineering',
            'education_level' => 'UNDERGRADUATE',
            'field_id' => Field::where('code', '071')->value('id'),
            'is_active' => '1',
            'institutions' => [Institution::where('code', 'NUST')->value('id')],
            'synonyms' => "Astro Eng\nSpace Engineering\n\n",
        ])->assertSessionHasNoErrors()->assertRedirect();

        $programme = Programme::with(['synonyms', 'institutions'])->where('name', 'BSc Astro Engineering')->firstOrFail();
        $this->assertSame(['Astro Eng', 'Space Engineering'], $programme->synonyms->pluck('synonym')->sort()->values()->all());
        $this->assertSame(['NUST'], $programme->institutions->pluck('code')->all());
        $this->assertSame(Programme::APPROVED, $programme->status);
        $this->assertDatabaseHas('audit_log', ['action' => 'CATALOGUE_CHANGED', 'actor_email' => $this->admin->email]);
    }

    public function test_a_duplicate_programme_at_the_same_level_is_refused(): void
    {
        $this->asAdmin()->post('/admin/catalogue/programmes', [
            'name' => 'bsc computer science',
            'education_level' => 'UNDERGRADUATE',
            'field_id' => Field::where('code', '061')->value('id'),
        ])->assertSessionHasErrors('name');
    }

    public function test_a_programme_needs_a_catalogue_level_and_a_real_field(): void
    {
        $this->asAdmin()->post('/admin/catalogue/programmes', [
            'name' => 'Whatever', 'education_level' => 'O_LEVEL', 'field_id' => 999999,
        ])->assertSessionHasErrors(['education_level', 'field_id']);
    }

    public function test_a_programme_can_be_edited_and_switched_off(): void
    {
        $programme = Programme::where('name', 'BSc Physics')->firstOrFail();

        $this->asAdmin()->post('/admin/catalogue/programmes/' . $programme->id, [
            'name' => 'BSc Physics (Honours)',
            'education_level' => 'UNDERGRADUATE',
            'field_id' => $programme->field_id,
            'synonyms' => 'Physics Honours',
        ])->assertSessionHasNoErrors();

        $this->assertSame('BSc Physics (Honours)', $programme->fresh()->name);
        $this->assertFalse($programme->fresh()->is_active, 'an unticked box means off');
        $this->assertSame(['Physics Honours'], $programme->fresh()->synonyms->pluck('synonym')->all());
    }

    public function test_editing_replaces_the_synonyms_and_institutions_with_what_the_form_says(): void
    {
        $programme = Programme::where('name', 'BSc Information Systems')->firstOrFail();
        $this->assertGreaterThan(1, $programme->synonyms()->count());

        $this->asAdmin()->post('/admin/catalogue/programmes/' . $programme->id, [
            'name' => $programme->name, 'education_level' => 'UNDERGRADUATE', 'field_id' => $programme->field_id,
            'is_active' => '1', 'synonyms' => 'BIS', 'institutions' => [],
        ])->assertSessionHasNoErrors();

        $this->assertSame(['BIS'], $programme->fresh()->synonyms->pluck('synonym')->all());
        $this->assertSame(0, $programme->institutions()->count());
    }

    // ------------------------------------------------- fields and institutions --

    public function test_a_narrow_field_can_be_added_under_a_broad_one(): void
    {
        $this->asAdmin()->post('/admin/catalogue/fields', [
            'code' => '0710', 'name' => 'Test narrow field', 'parent_id' => Field::where('code', '07')->value('id'),
        ])->assertSessionHasNoErrors();

        $this->assertSame(Field::where('code', '07')->value('id'), Field::where('code', '0710')->value('parent_id'));
    }

    public function test_a_field_code_must_be_unique_and_start_with_its_parents(): void
    {
        $this->asAdmin()->post('/admin/catalogue/fields', ['code' => '071', 'name' => 'Dup'])->assertSessionHasErrors('code');
        $this->asAdmin()->post('/admin/catalogue/fields', [
            'code' => '999', 'name' => 'Wrong parent', 'parent_id' => Field::where('code', '07')->value('id'),
        ])->assertSessionHasErrors('code');
    }

    public function test_an_institution_can_be_added_and_edited(): void
    {
        $this->asAdmin()->post('/admin/catalogue/institutions', [
            'code' => 'TESTU', 'name' => 'Test University', 'type' => 'university', 'province' => 'Harare', 'is_active' => '1',
        ])->assertSessionHasNoErrors();

        $institution = Institution::where('code', 'TESTU')->firstOrFail();

        $this->asAdmin()->post('/admin/catalogue/institutions/' . $institution->id, [
            'code' => 'TESTU', 'name' => 'Renamed University', 'type' => 'other', 'province' => '', 'is_active' => '1',
        ])->assertSessionHasNoErrors();

        $this->assertSame('Renamed University', $institution->fresh()->name);
        $this->assertNull($institution->fresh()->province);
    }

    public function test_an_institution_needs_a_known_type_and_province(): void
    {
        $this->asAdmin()->post('/admin/catalogue/institutions', [
            'code' => 'BAD', 'name' => 'Bad', 'type' => 'spaceport', 'province' => 'Atlantis',
        ])->assertSessionHasErrors(['type', 'province']);
    }

    // ---------------------------------------------------------------- the queue --

    private function pending(string $name = 'Computing Science'): Programme
    {
        return app(ProgrammeCatalogue::class)->suggest(
            User::where('email', 'tanaka.chirwa@scholarzim.co.zw')->firstOrFail(),
            $name, EducationLevel::UNDERGRADUATE, Field::where('code', '061')->value('id')
        );
    }

    public function test_the_queue_lists_what_people_suggested_and_who(): void
    {
        $this->pending('Quantum Basketweaving');

        $this->asAdmin()->get('/admin/catalogue/pending')->assertOk()
            ->assertSee('Quantum Basketweaving')
            ->assertSee('Tanaka Chirwa');
    }

    public function test_approve_merge_and_reject_work_from_the_queue(): void
    {
        $a = $this->pending('Approve Me');
        $b = $this->pending('Computing Science');
        $c = $this->pending('Reject Me');
        $target = Programme::where('name', 'BSc Computer Science')->firstOrFail();

        $this->asAdmin()->post("/admin/catalogue/pending/{$a->id}/approve")->assertSessionHasNoErrors();
        $this->asAdmin()->post("/admin/catalogue/pending/{$b->id}/merge", ['into' => $target->id])->assertSessionHasNoErrors();
        $this->asAdmin()->post("/admin/catalogue/pending/{$c->id}/reject")->assertSessionHasNoErrors();

        $this->assertSame(Programme::APPROVED, $a->fresh()->status);
        $this->assertSame($target->id, $b->fresh()->merged_into_id);
        $this->assertSame('rejected', $c->fresh()->status);
    }

    public function test_a_bad_merge_target_is_reported_not_crashed_on(): void
    {
        $pending = $this->pending();
        $diploma = Programme::where('name', 'Diploma in Information Technology')->firstOrFail();

        $this->asAdmin()->post("/admin/catalogue/pending/{$pending->id}/merge", ['into' => $diploma->id])
            ->assertSessionHas('errorMessage');

        $this->assertSame(Programme::PENDING, $pending->fresh()->status);
    }

    // ------------------------------------------------------------- import/export --

    private function upload(string $kind, string $contents, string $name = 'file.csv')
    {
        return $this->asAdmin()->post('/admin/catalogue/import', [
            'kind' => $kind,
            'file' => UploadedFile::fake()->createWithContent($name, $contents),
        ]);
    }

    public function test_an_uploaded_file_is_imported_and_the_result_is_shown(): void
    {
        $response = $this->upload('programmes', "name,level,field_code,institutions,synonyms\nBSc Rocketry,Undergraduate,071,NUST,Rockets\n");

        $response->assertSessionHasNoErrors()->assertRedirect('/admin/catalogue');
        $this->assertNotNull(Programme::where('name', 'BSc Rocketry')->first());
        $this->assertDatabaseHas('audit_log', ['action' => 'CATALOGUE_IMPORT']);

        $this->asAdmin()->get('/admin/catalogue')->assertSee('1 added');
    }

    public function test_refused_rows_are_listed_with_their_row_and_reason(): void
    {
        $this->upload('programmes', "name,level,field_code,institutions,synonyms\nGood One,Diploma,061,,\nBad One,Kindergarten,061,,\n");

        $this->asAdmin()->get('/admin/catalogue')->assertSee('Row 2')->assertSee('level must be');
        $this->assertNotNull(Programme::where('name', 'Good One')->first());
        $this->assertNull(Programme::where('name', 'Bad One')->first());
    }

    public function test_a_file_missing_required_columns_is_explained(): void
    {
        $this->upload('programmes', "title,grade\nx,y\n");

        $this->asAdmin()->get('/admin/catalogue')->assertSee('missing the column');
    }

    public function test_only_csv_and_xlsx_uploads_are_accepted(): void
    {
        $this->upload('programmes', 'x', 'malware.php')->assertSessionHasErrors('file');
        $this->upload('programmes', 'x', 'notes.txt')->assertSessionHasErrors('file');
    }

    public function test_an_unknown_kind_is_refused(): void
    {
        $this->upload('users', "a,b\n1,2\n")->assertSessionHasErrors('kind');
    }

    public function test_the_catalogue_can_be_exported_as_csv_and_xlsx(): void
    {
        $csv = $this->asAdmin()->get('/admin/catalogue/export/programmes.csv');
        $csv->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('name,level,field_code,institutions,synonyms', $csv->getContent());

        $xlsx = $this->asAdmin()->get('/admin/catalogue/export/institutions.xlsx');
        $xlsx->assertOk();
        $this->assertStringStartsWith('PK', $xlsx->getContent(), 'an .xlsx is a zip file');
    }

    public function test_an_export_can_be_imported_straight_back(): void
    {
        $csv = $this->asAdmin()->get('/admin/catalogue/export/programmes.csv')->getContent();
        $before = Programme::count();

        $this->upload('programmes', $csv);

        $this->assertSame($before, Programme::count());
        $this->asAdmin()->get('/admin/catalogue')->assertSee('0 added');
    }

    public function test_an_unknown_export_kind_is_a_404(): void
    {
        $this->asAdmin()->get('/admin/catalogue/export/users.csv')->assertNotFound();
    }
}
