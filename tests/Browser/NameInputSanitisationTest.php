<?php

namespace Tests\Browser;

use App\Models\User;
use App\Support\RoleNames;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * resources/js/name-input.js strips digits/punctuation from a name field as
 * it is typed or pasted into - a real browser behaviour the HTTP-level
 * Feature test suite cannot exercise, since it never runs JavaScript.
 *
 * These tests only drive the field itself, not form submission - the
 * server-side FormOptions::NAME_PATTERN rule this script is a convenience
 * in front of is already covered by InputValidationHardeningTest. What is
 * missing without a real browser is proof that the live-stripping behaviour
 * itself actually runs, and that the admin "create user" form's
 * role-dependent skip (student/admin get it, provider does not, because a
 * provider's "name" is an organisation name) behaves correctly.
 */
class NameInputSanitisationTest extends DuskTestCase
{
    public function test_typing_digits_into_the_registration_full_name_field_strips_them_live(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/register')
                ->type('full_name', 'John123 Doe456')
                ->assertInputValue('full_name', 'John Doe');
        });
    }

    /**
     * The 'input' event fires once a paste has landed, so this dispatches it
     * the same way a real paste does - setting the value directly rather
     * than driving the OS clipboard, which is not reliably automatable
     * headless. What is under test either way is name-input.js's own
     * listener, not WebDriver's clipboard integration.
     */
    public function test_pasting_a_name_with_digits_strips_them_too(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/register')
                ->script([
                    "var el = document.querySelector('[name=full_name]');"
                    . "el.value = 'John123 Doe456';"
                    . "el.dispatchEvent(new Event('input', {bubbles: true}));",
                ]);

            $browser->assertInputValue('full_name', 'John Doe');
        });
    }

    public function test_admin_create_user_strips_digits_for_a_student_role(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->loginAs($this->admin())
                ->visit('/admin/users/create')
                ->select('role_name', RoleNames::APPLICANT)
                ->type('full_name', 'Jane99 Doe')
                ->assertInputValue('full_name', 'Jane Doe');
        });
    }

    /** A provider account's "name" is an organisation name, which may legitimately contain digits. */
    public function test_admin_create_user_allows_digits_for_a_provider_role(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->loginAs($this->admin())
                ->visit('/admin/users/create')
                ->select('role_name', RoleNames::PROVIDER)
                ->type('full_name', 'Chikafu Trust 2026')
                ->assertInputValue('full_name', 'Chikafu Trust 2026');
        });
    }

    /** Switching away from Provider must strip whatever digits were typed while it was selected. */
    public function test_switching_role_away_from_provider_retroactively_strips_digits(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->loginAs($this->admin())
                ->visit('/admin/users/create')
                ->select('role_name', RoleNames::PROVIDER)
                ->type('full_name', 'Chikafu Trust 2026')
                ->select('role_name', RoleNames::APPLICANT)
                ->assertInputValue('full_name', 'Chikafu Trust ');
        });
    }

    private function admin(): User
    {
        return User::whereHas('role', fn ($q) => $q->where('role_name', RoleNames::ADMIN))->firstOrFail();
    }
}
