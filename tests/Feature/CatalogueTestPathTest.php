<?php

namespace Tests\Feature;

use App\Models\Programme;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Seeded tests load the programme catalogue, and the shipped one is over a thousand programmes. Tests therefore
 * default to a small copy, and a test about the real data asks for it. This keeps the suite fast without hiding
 * the real catalogue from the tests that matter.
 */
class CatalogueTestPathTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_seeded_test_gets_the_small_catalogue_by_default(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertGreaterThan(100, Programme::count());
        $this->assertLessThan(400, Programme::count());
    }

    public function test_a_test_can_ask_for_the_real_shipped_catalogue(): void
    {
        $this->useFullCatalogue();
        $this->seed(DatabaseSeeder::class);

        $this->assertGreaterThan(1000, Programme::count());
    }
}