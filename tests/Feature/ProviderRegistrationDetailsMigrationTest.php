<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Opportunity;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The migration that adds where a provider is registered. Run against data from
 * before it existed: it is taken down, the old-shaped rows are checked, then it
 * runs again - and nothing that was there may change.
 */
class ProviderRegistrationDetailsMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const NEW_COLUMNS = [
        'registration_country', 'registration_number_note', 'contact_name', 'contact_role',
        'info_requested_at', 'info_requested_by', 'info_request_note', 'info_provided_at',
    ];

    private object $migration;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->migration = require database_path('migrations/2025_01_01_000020_add_registration_details_to_provider_profiles.php');
    }

    public function test_existing_providers_listings_and_applications_survive_untouched(): void
    {
        $this->migration->down();

        foreach (self::NEW_COLUMNS as $column) {
            $this->assertFalse(Schema::hasColumn('provider_profiles', $column), $column . ' is removed on rollback');
        }
        $this->assertTrue(Schema::hasColumn('provider_profiles', 'registration_number'), 'the older column is not this migration\'s to drop');

        $profilesBefore = DB::table('provider_profiles')->orderBy('profile_id')->get()->map(fn ($row) => (array) $row)->all();
        $usersBefore = User::whereIn('user_id', array_column($profilesBefore, 'user_id'))->orderBy('user_id')
            ->get(['user_id', 'email', 'account_status', 'full_name'])->toArray();
        $opportunities = Opportunity::count();
        $applications = Application::count();
        $this->assertNotEmpty($profilesBefore);

        $this->migration->up();

        foreach (DB::table('provider_profiles')->orderBy('profile_id')->get() as $i => $row) {
            $row = (array) $row;

            foreach ($profilesBefore[$i] as $column => $value) {
                $this->assertEquals($value, $row[$column], "provider_profiles.{$column} changed for profile {$row['profile_id']}");
            }

            // Nothing is assumed: no country is filled in for anyone.
            foreach (self::NEW_COLUMNS as $column) {
                $this->assertNull($row[$column], "{$column} must not be backfilled");
            }
        }

        $this->assertSame($usersBefore, User::whereIn('user_id', array_column($profilesBefore, 'user_id'))->orderBy('user_id')
            ->get(['user_id', 'email', 'account_status', 'full_name'])->toArray());
        $this->assertSame($opportunities, Opportunity::count());
        $this->assertSame($applications, Application::count());
    }

    public function test_the_new_columns_are_nullable(): void
    {
        $columns = collect(Schema::getColumns('provider_profiles'))->keyBy('name');

        foreach (self::NEW_COLUMNS as $column) {
            $this->assertTrue($columns->has($column), $column . ' exists');
            $this->assertTrue($columns[$column]['nullable'], $column . ' is nullable, so older rows stay valid');
        }
    }
}
