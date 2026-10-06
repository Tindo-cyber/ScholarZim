<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Which alerts flash-alerts.js is allowed to dismiss, decided by markup.
 *
 * The timing itself is exercised in a real browser by
 * tests/Browser/FlashAlertsTest.php (Dusk); this project has no JavaScript
 * unit runner, so this pins the two things a PHPUnit run can see: which
 * alerts opt in, and the script's own delay.
 */
class FlashAlertsMarkupTest extends TestCase
{
    public function test_session_success_and_error_flashes_opt_in_to_auto_dismissal(): void
    {
        session()->flash('successMessage', 'Profile saved.');
        session()->flash('errorMessage', 'That did not work.');

        $html = (string) $this->withViewErrors([])->blade('<x-flash-alerts />');

        $this->assertMatchesRegularExpression('/class="alert alert-success[^"]*"[^>]*data-sz-autodismiss/', $html);
        $this->assertMatchesRegularExpression('/class="alert alert-danger alert-dismissible[^"]*"[^>]*data-sz-autodismiss/', $html);
        // The close button stays.
        $this->assertSame(2, substr_count($html, 'data-bs-dismiss="alert"'));
    }

    public function test_the_validation_summary_does_not_opt_in(): void
    {
        $html = (string) $this->withViewErrors(['email' => 'The email field is required.'])
            ->blade('<x-flash-alerts />');

        $this->assertStringContainsString('Please fix the following:', $html);
        $this->assertStringNotContainsString('data-sz-autodismiss', $html);
    }

    public function test_the_script_dismisses_after_two_seconds_without_a_hover_exception(): void
    {
        $script = file_get_contents(resource_path('js/flash-alerts.js'));

        $this->assertMatchesRegularExpression('/const AUTO_DISMISS_MS = 2000;/', $script);
        $this->assertStringContainsString('[data-sz-autodismiss]', $script);
        $this->assertStringNotContainsString(':hover', $script);
        $this->assertStringNotContainsString('activeElement', $script);
    }
}
