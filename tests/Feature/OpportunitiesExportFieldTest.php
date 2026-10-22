<?php

namespace Tests\Feature;

use App\Support\OpportunityModerationStatus;
use App\Support\OpportunityStatus;
use App\Models\Field;
use App\Models\Opportunity;
use App\Models\OpportunityScope;
use App\Models\Programme;
use App\Models\User;
use App\Services\Catalogue\ListingScopes;
use App\Services\ExcelReportService;
use App\Support\EducationLevel;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * The Field and Open to columns of the opportunities spreadsheet. They are read from the catalogue; the export never
 * changes a listing or who it is open to.
 */
class OpportunitiesExportFieldTest extends TestCase
{
    use RefreshDatabase;

    private User $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->provider = User::where('email', 'provider@scholarzim.co.zw')->firstOrFail();
    }

    private function listing(string $title, array $attributes = []): Opportunity
    {
        return Opportunity::create($attributes + [
            'provider_user_id' => $this->provider->user_id, 'provider_name' => $this->provider->full_name, 'title' => $title,
            'description' => 'A fixture.', 'education_level' => EducationLevel::UNDERGRADUATE, 'funding_type' => 'Full Scholarship',
            'country' => 'Zimbabwe', 'target_country' => 'Zimbabwe', 'deadline' => '2030-12-31',
            'status' => OpportunityStatus::ACTIVE, 'moderation_status' => OpportunityModerationStatus::APPROVED, 'submitted_at' => now()->subDays(5),
        ]);
    }

    private function scope(Opportunity $listing, string $column, int $id): void
    {
        OpportunityScope::create(['opportunity_id' => $listing->opportunity_id, $column => $id]);
    }

    /** @return array<string, array{field: ?string, open_to: ?string}> keyed by title */
    private function export(): array
    {
        $path = tempnam(sys_get_temp_dir(), 'szx');
        file_put_contents($path, app(ExcelReportService::class)->opportunitiesExcel());

        try {
            $rows = IOFactory::load($path)->getActiveSheet()->toArray(null, false, false, false);
        } finally {
            @unlink($path);
        }

        $header = array_shift($rows);
        $this->assertSame(['Title', 'Provider', 'Education Level', 'Field', 'Location', 'Funding', 'Deadline', 'Status', 'Open to'], $header);

        $out = [];

        foreach ($rows as $row) {
            $out[$row[0]] = ['field' => $row[3], 'open_to' => $row[8]];
        }

        return $out;
    }

    /** @return array{0: Programme, 1: Programme} two catalogue programmes that belong to different fields */
    private function twoProgrammesInDifferentFields(): array
    {
        $first = Programme::inCatalogue()->whereNotNull('field_id')->orderBy('id')->firstOrFail();
        $second = Programme::inCatalogue()->whereNotNull('field_id')->where('field_id', '!=', $first->field_id)->orderBy('id')->firstOrFail();

        return [$first->load('field'), $second->load('field')];
    }

    public function test_a_programme_targeted_listing_shows_the_field_of_its_programme_and_says_it_is_programme_only(): void
    {
        [$programme] = $this->twoProgrammesInDifferentFields();
        $listing = $this->listing('Export Programme Award', ['target_field' => null]);
        $this->scope($listing, 'programme_id', $programme->id);

        $row = $this->export()['Export Programme Award'];

        $this->assertSame($programme->field->label(), $row['field']);
        $this->assertSame($programme->name, $row['open_to'], 'only the programme, not the whole field');
        $this->assertStringNotContainsString('any ', $row['open_to']);
    }

    public function test_several_programmes_list_each_field_once(): void
    {
        [$a, $b] = $this->twoProgrammesInDifferentFields();
        $sameFieldAsA = Programme::inCatalogue()->where('field_id', $a->field_id)->where('id', '!=', $a->id)->first();

        $listing = $this->listing('Export Many Award');

        foreach (array_filter([$a, $b, $sameFieldAsA]) as $p) {
            $this->scope($listing, 'programme_id', $p->id);
        }

        $labels = explode('; ', $this->export()['Export Many Award']['field']);

        $this->assertEqualsCanonicalizing(array_values(array_unique([$a->field->label(), $b->field->label()])), $labels);
        $this->assertSame(count($labels), count(array_unique($labels)), 'a field is named once');
    }

    public function test_a_field_targeted_listing_shows_the_field_and_says_it_is_open_to_the_whole_field(): void
    {
        $field = Field::whereNotNull('parent_id')->orderBy('id')->firstOrFail();
        $listing = $this->listing('Export Field Award');
        $this->scope($listing, 'field_id', $field->id);

        $row = $this->export()['Export Field Award'];

        $this->assertSame($field->label(), $row['field']);
        $this->assertStringContainsString('any ', $row['open_to']);
        $this->assertStringContainsString($field->label(), $row['open_to']);
    }

    public function test_a_legacy_listing_keeps_its_free_text_field(): void
    {
        $this->listing('Export Legacy Award', ['target_field' => 'Agriculture']);

        $row = $this->export()['Export Legacy Award'];

        $this->assertSame('Agriculture', $row['field']);
        $this->assertNull($row['open_to']);
    }

    public function test_the_catalogue_wins_over_a_stale_free_text_field(): void
    {
        [$programme] = $this->twoProgrammesInDifferentFields();
        $listing = $this->listing('Export Both Award', ['target_field' => 'Something Old']);
        $this->scope($listing, 'programme_id', $programme->id);

        $this->assertSame($programme->field->label(), $this->export()['Export Both Award']['field']);
    }

    public function test_a_listing_open_to_everyone_has_no_field_and_says_so_while_an_undecided_one_is_blank(): void
    {
        $this->listing('Export Open Award', ['target_field' => null, 'open_to_all_programmes' => true]);
        $this->listing('Export Undecided Award', ['target_field' => null]);

        $export = $this->export();

        $this->assertNull($export['Export Open Award']['field']);
        $this->assertSame('All programmes', $export['Export Open Award']['open_to']);
        $this->assertNull($export['Export Undecided Award']['open_to'], 'not stated is not the same as everyone');
    }

    public function test_missing_catalogue_relationships_fall_back_instead_of_failing(): void
    {
        $scopes = app(ListingScopes::class);
        $noProgramme = new OpportunityScope(['programme_id' => 999999]);
        $noField = (new OpportunityScope(['programme_id' => 1]))->setRelation('programme', new Programme(['name' => 'No Field']));

        $this->assertSame('Kept Text', $scopes->fieldLabels(collect([$noProgramme, $noField]), '  Kept Text '));
        $this->assertNull($scopes->fieldLabels(collect([$noProgramme, $noField]), null));
        $this->assertNull($scopes->fieldLabels(collect(), ''));
    }

    public function test_exporting_changes_no_listing_and_no_scope(): void
    {
        [$programme] = $this->twoProgrammesInDifferentFields();
        $listing = $this->listing('Export Untouched Award', ['target_field' => 'Original']);
        $this->scope($listing, 'programme_id', $programme->id);

        $snapshot = fn () => [Opportunity::orderBy('opportunity_id')->get()->toArray(), OpportunityScope::orderBy('id')->get()->toArray()];

        $before = $snapshot();
        $this->export();

        $this->assertSame($before, $snapshot());
        $this->assertSame([$programme->id], $listing->scopes()->pluck('programme_id')->all(), 'a programme never became its whole field');
    }
}
