<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Field;
use App\Models\Programme;
use App\Models\ProgrammeFieldReview;
use App\Models\User;
use App\Services\Catalogue\ProgrammeReclassifier;
use App\Support\EducationLevel;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The administrator's screens for a three-level field list: adding and editing at every level, refusing what would break
 * the tree, and confirming or dismissing the moves ProgrammeReclassifier proposes.
 */
class AdminFieldHierarchyTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->useFullCatalogue();
        app(\App\Services\Catalogue\CatalogueImporter::class)->importFile('fields', database_path('seeders/data/fields.csv'));  // the field list only, not the programmes
        $this->admin = User::where('email', 'admin@scholarzim.co.zw')->firstOrFail();
    }

    private function id(string $code): int
    {
        return Field::where('code', $code)->value('id');
    }

    public function test_the_fields_page_lists_all_three_levels_and_marks_headings(): void
    {
        $html = $this->actingAs($this->admin)->get('/admin/catalogue/fields')->assertOk()->getContent();

        $this->assertStringContainsString('0412', $html);
        $this->assertStringContainsString('Finance, banking and insurance', $html);
        $this->assertStringContainsString('heading only', $html);
        $this->assertStringContainsString('not further defined', $html);
    }

    public function test_a_detailed_field_can_be_added_under_a_narrow_one(): void
    {
        $this->actingAs($this->admin)->post('/admin/catalogue/fields', [
            'code' => '0418', 'name' => 'Test detailed field', 'parent_id' => $this->id('041'),
        ])->assertSessionHasNoErrors();

        $field = Field::where('code', '0418')->firstOrFail();
        $this->assertSame('detailed', $field->level);
        $this->assertSame($this->id('041'), $field->parent_id);
        $this->assertTrue(AuditLog::where('entity_type', 'Field')->where('entity_id', $field->id)->exists());
    }

    public function test_a_wrong_code_length_or_prefix_is_refused(): void
    {
        $this->actingAs($this->admin)->post('/admin/catalogue/fields', ['code' => '04181', 'name' => 'Too long', 'parent_id' => $this->id('041')])->assertSessionHasErrors('code');
        $this->actingAs($this->admin)->post('/admin/catalogue/fields', ['code' => '0318', 'name' => 'Wrong prefix', 'parent_id' => $this->id('041')])->assertSessionHasErrors('code');
        $this->actingAs($this->admin)->post('/admin/catalogue/fields', ['code' => '0418', 'name' => 'No parent'])->assertSessionHasErrors('parent_id');
        $this->assertNull(Field::where('code', '0418')->first());
    }

    public function test_a_detailed_field_cannot_be_used_as_a_parent(): void
    {
        $this->actingAs($this->admin)->post('/admin/catalogue/fields', [
            'code' => '04128', 'name' => 'Too deep', 'parent_id' => $this->id('0412'),
        ])->assertSessionHasErrors('parent_id');
        $this->assertNull(Field::where('code', '04128')->first());
    }

    public function test_a_field_can_be_switched_off_and_a_rename_does_not_do_it(): void
    {
        $id = $this->id('0415');

        $this->actingAs($this->admin)->post('/admin/catalogue/fields/' . $id, ['code' => '0415', 'name' => 'Secretarial work renamed'])->assertSessionHasNoErrors();
        $field = Field::find($id);
        $this->assertSame('Secretarial work renamed', $field->name);
        $this->assertTrue($field->is_active);
        $this->assertTrue($field->is_selectable);
        $this->assertSame($this->id('041'), $field->parent_id, 'a rename leaves it where it is');

        $this->actingAs($this->admin)->post('/admin/catalogue/fields/' . $id, ['code' => '0415', 'name' => 'Secretarial work renamed', 'is_active' => '0', 'is_selectable' => '0'])->assertSessionHasNoErrors();
        $this->assertFalse(Field::find($id)->is_active);
        $this->assertFalse(Field::find($id)->is_selectable);
    }

    public function test_the_review_queue_shows_a_proposal_and_accepting_it_files_the_programme_and_audits_it(): void
    {
        $programme = Programme::create([
            'name' => 'BCom Accounting and Finance', 'education_level' => EducationLevel::UNDERGRADUATE,
            'field_id' => $this->id('041'), 'is_active' => true, 'status' => Programme::APPROVED,
        ]);
        app(ProgrammeReclassifier::class)->run(true);
        $review = ProgrammeFieldReview::where('programme_id', $programme->id)->firstOrFail();

        $this->actingAs($this->admin)->get('/admin/catalogue/field-reviews')->assertOk()
            ->assertSee('BCom Accounting and Finance')->assertSee($review->reason);

        $this->actingAs($this->admin)->post('/admin/catalogue/field-reviews/' . $review->id . '/accept')->assertSessionHasNoErrors();

        $this->assertSame($review->to_field_id, $programme->fresh()->field_id);
        $this->assertSame('applied', $review->fresh()->status);
        $this->assertSame($this->admin->email, $review->fresh()->decided_by);
        $this->assertTrue(AuditLog::where('entity_type', 'Programme')->where('entity_id', $programme->id)->where('actor_email', $this->admin->email)->exists());
    }

    public function test_dismissing_a_proposal_leaves_the_programme_alone_and_it_is_not_raised_again(): void
    {
        $programme = Programme::create([
            'name' => 'BCom Accounting and Finance', 'education_level' => EducationLevel::UNDERGRADUATE,
            'field_id' => $this->id('041'), 'is_active' => true, 'status' => Programme::APPROVED,
        ]);
        app(ProgrammeReclassifier::class)->run(true);
        $review = ProgrammeFieldReview::where('programme_id', $programme->id)->firstOrFail();

        $this->actingAs($this->admin)->post('/admin/catalogue/field-reviews/' . $review->id . '/dismiss')->assertSessionHasNoErrors();
        app(ProgrammeReclassifier::class)->run(true);

        $this->assertSame($this->id('041'), $programme->fresh()->field_id);
        $this->assertSame('dismissed', $review->fresh()->status);
        $this->actingAs($this->admin)->get('/admin/catalogue/field-reviews')->assertDontSee('BCom Accounting and Finance');
    }

    public function test_only_an_administrator_can_decide_a_review(): void
    {
        $programme = Programme::create([
            'name' => 'BCom Accounting and Finance', 'education_level' => EducationLevel::UNDERGRADUATE,
            'field_id' => $this->id('041'), 'is_active' => true, 'status' => Programme::APPROVED,
        ]);
        app(ProgrammeReclassifier::class)->run(true);
        $review = ProgrammeFieldReview::where('programme_id', $programme->id)->firstOrFail();
        $student = User::where('email', 'tanaka.chirwa@scholarzim.co.zw')->firstOrFail();

        $this->actingAs($student)->post('/admin/catalogue/field-reviews/' . $review->id . '/accept')->assertForbidden();
        $this->assertSame('pending', $review->fresh()->status);
        $this->assertSame($this->id('041'), $programme->fresh()->field_id);
    }
}
