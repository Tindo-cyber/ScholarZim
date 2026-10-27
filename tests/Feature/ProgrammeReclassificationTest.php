<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Field;
use App\Models\Programme;
use App\Models\ProgrammeFieldReview;
use App\Services\Catalogue\ProgrammeFieldRules;
use App\Services\Catalogue\ProgrammeReclassifier;
use App\Support\EducationLevel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Programmes are refined to a detailed field only where their names justify it; anything less certain is queued for a
 * person; nothing is deleted, and doing it twice changes nothing more.
 */
class ProgrammeReclassificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useFullCatalogue();
        app(\App\Services\Catalogue\CatalogueImporter::class)->importFile('fields', database_path('seeders/data/fields.csv'));  // the field list only, not the programmes
    }

    private function programme(string $name, string $fieldCode): Programme
    {
        return Programme::create([
            'name' => $name, 'education_level' => EducationLevel::UNDERGRADUATE,
            'field_id' => Field::where('code', $fieldCode)->value('id'), 'is_active' => true, 'status' => Programme::APPROVED,
        ]);
    }

    private function codeOf(Programme $programme): string
    {
        return Field::find($programme->fresh()->field_id)->code;
    }

    public function test_every_rule_names_a_real_detailed_field_and_a_basis(): void
    {
        $codes = Field::query()->pluck('level', 'code');

        foreach (ProgrammeFieldRules::all() as $narrow => $rules) {
            $this->assertSame('narrow', $codes[$narrow] ?? null, "$narrow is a narrow field");

            foreach ($rules as [$pattern, $target, $confidence, $basis]) {
                $this->assertSame('detailed', $codes[$target] ?? null, "$target in a rule for $narrow");
                $this->assertContains($confidence, ['high', 'medium']);
                $this->assertNotSame('', trim($basis));
                $this->assertNotFalse(@preg_match($pattern, ''), "pattern $pattern is valid");
            }
        }
    }

    public function test_a_clear_name_is_refined_inside_its_narrow_field(): void
    {
        $banking = $this->programme('BCom Banking and Finance', '041');
        $accounting = $this->programme('Diploma in Accounting', '041');
        $marketing = $this->programme('BSc Marketing', '041');
        $law = $this->programme('Bachelor of Laws', '042');

        $result = app(ProgrammeReclassifier::class)->run(true);

        $this->assertSame('0412', $this->codeOf($banking));
        $this->assertSame('0411', $this->codeOf($accounting));
        $this->assertSame('0414', $this->codeOf($marketing));
        $this->assertSame('0421', $this->codeOf($law));
        $this->assertGreaterThanOrEqual(4, $result['applied']);
    }

    public function test_two_disciplines_in_one_name_are_queued_not_decided(): void
    {
        $mixed = $this->programme('BCom Accounting and Finance', '041');

        app(ProgrammeReclassifier::class)->run(true);

        $this->assertSame('041', $this->codeOf($mixed), 'left where it was');
        $review = ProgrammeFieldReview::where('programme_id', $mixed->id)->firstOrFail();
        $this->assertSame('pending', $review->status);
        $this->assertSame('medium', $review->confidence);
        $this->assertNotSame('', $review->reason);
    }

    public function test_a_move_to_another_narrow_field_is_never_made_automatically(): void
    {
        $planning = $this->programme('BSc Spatial Planning and Management', '041');

        app(ProgrammeReclassifier::class)->run(true);

        $this->assertSame('041', $this->codeOf($planning));
        $review = ProgrammeFieldReview::where('programme_id', $planning->id)->firstOrFail();
        $this->assertSame('move', $review->kind);
        $this->assertSame('0731', Field::find($review->to_field_id)->code);
        $this->assertSame('pending', $review->status);
    }

    public function test_a_name_no_rule_recognises_stays_in_its_narrow_field(): void
    {
        $odd = $this->programme('Certificate in Basket Weaving Studies', '041');

        $result = app(ProgrammeReclassifier::class)->run(true);

        $this->assertSame('041', $this->codeOf($odd));
        $this->assertNull(ProgrammeFieldReview::where('programme_id', $odd->id)->first());
        $this->assertGreaterThanOrEqual(1, $result['unmatched']);
    }

    public function test_a_report_run_changes_nothing(): void
    {
        $banking = $this->programme('BCom Banking and Finance', '041');
        $mixed = $this->programme('BCom Accounting and Finance', '041');

        $result = app(ProgrammeReclassifier::class)->run(false);

        $this->assertSame('041', $this->codeOf($banking));
        $this->assertSame(0, ProgrammeFieldReview::count());
        $this->assertSame(0, AuditLog::where('entity_type', 'Programme')->count());
        $this->assertGreaterThanOrEqual(1, $result['applied']);
        $this->assertGreaterThanOrEqual(1, $result['queued']);
        $this->assertContains($banking->id, array_column($result['rows'], 'programme_id'));
        $this->assertContains($mixed->id, array_column($result['rows'], 'programme_id'));
    }

    public function test_a_change_is_audited_with_the_old_and_new_field_and_the_reason(): void
    {
        $banking = $this->programme('BCom Banking and Finance', '041');

        app(ProgrammeReclassifier::class)->run(true);

        $entry = AuditLog::where('entity_type', 'Programme')->where('entity_id', $banking->id)->firstOrFail();
        $this->assertSame('system', $entry->actor_email);
        $this->assertSame('041', $entry->old_values['field']);
        $this->assertSame('0412', $entry->new_values['field']);
        $this->assertNotEmpty($entry->reason);
    }

    public function test_running_it_again_changes_nothing_more_and_keeps_decisions(): void
    {
        $banking = $this->programme('BCom Banking and Finance', '041');
        $mixed = $this->programme('BCom Accounting and Finance', '041');
        $reclassifier = app(ProgrammeReclassifier::class);

        $reclassifier->run(true);
        $review = ProgrammeFieldReview::where('programme_id', $mixed->id)->firstOrFail();
        $review->update(['status' => ProgrammeFieldReview::DISMISSED]);
        $audited = AuditLog::count();

        $second = $reclassifier->run(true);

        $this->assertSame('0412', $this->codeOf($banking));
        $this->assertSame($audited, AuditLog::count(), 'no second audit line for a change already made');
        $this->assertSame(1, ProgrammeFieldReview::where('programme_id', $mixed->id)->count());
        $this->assertSame('dismissed', $review->fresh()->status, 'a decision is not reopened');
        $this->assertSame(0, $second['applied']);
    }

    public function test_programme_ids_and_counts_are_untouched(): void
    {
        $banking = $this->programme('BCom Banking and Finance', '041');
        $total = Programme::count();

        app(ProgrammeReclassifier::class)->run(true);

        $this->assertSame($total, Programme::count());
        $this->assertNotNull(Programme::find($banking->id));
        $this->assertSame('BCom Banking and Finance', Programme::find($banking->id)->name);
    }

    public function test_the_command_defaults_to_a_dry_run(): void
    {
        $banking = $this->programme('BCom Banking and Finance', '041');

        $this->artisan('catalogue:reclassify')->expectsOutputToContain('Nothing was changed')->assertExitCode(0);
        $this->assertSame('041', $this->codeOf($banking));

        $this->artisan('catalogue:reclassify', ['--apply' => true])->assertExitCode(0);
        $this->assertSame('0412', $this->codeOf($banking));
    }

    public function test_the_command_can_write_its_proposals_to_a_file(): void
    {
        $this->programme('BCom Banking and Finance', '041');
        $path = tempnam(sys_get_temp_dir(), 'rcl');

        $this->artisan('catalogue:reclassify', ['--report' => $path])->assertExitCode(0);

        $csv = file_get_contents($path);
        unlink($path);
        $this->assertStringContainsString('programme_id,programme,level,from,to,kind,confidence,action,reason', $csv);
        $this->assertStringContainsString('BCom Banking and Finance', $csv);
    }
}
