<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The contact address in the footers: an email only, configurable, and no phone number on the site. */
class ContactInfoTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_public_footer_shows_the_contact_email_as_a_link_and_no_phone_number(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('href="mailto:r231425y@students.msu.ac.zw"', $html);
        $this->assertStringNotContainsString('0778862767', $html);
    }

    public function test_the_signed_in_footer_links_to_the_same_address(): void
    {
        $this->seed(DatabaseSeeder::class);
        $student = User::where('email', 'student@scholarzim.co.zw')->firstOrFail();

        $this->actingAs($student)->get(route('applicant.dashboard'))->assertOk()
            ->assertSee('mailto:r231425y@students.msu.ac.zw', false);
    }

    public function test_the_address_can_be_changed_or_hidden_by_configuration(): void
    {
        config(['scholarzim.contact.email' => 'office@example.org']);
        $this->get('/')->assertSee('mailto:office@example.org', false)->assertDontSee('r231425y@', false);

        config(['scholarzim.contact.email' => '']);
        $this->get('/')->assertDontSee('mailto:', false);
    }

    public function test_no_phone_number_is_published_anywhere_in_the_views_or_docs(): void
    {
        $found = [];

        foreach (['resources/views', 'docs', 'config'] as $dir) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path($dir), \FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file->isFile() && str_contains((string) file_get_contents($file->getPathname()), '0778862767')) {
                    $found[] = $file->getPathname();
                }
            }
        }

        $this->assertSame([], $found);
    }
}
