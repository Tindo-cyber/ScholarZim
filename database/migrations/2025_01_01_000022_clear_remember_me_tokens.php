<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * "Keep me signed in" has been removed: closing the browser ends the login session, and nothing remembers a person
 * across browser sessions any more.
 *
 * Removing the checkbox stops new remember-me cookies being issued, but a browser that already holds one still carries a
 * cookie that Laravel's session guard will honour for as long as the matching token is stored on the user. Clearing the
 * stored tokens is what makes those cookies useless: a token that is null never matches, so the guard refuses the cookie
 * and the person is simply asked to sign in. Nothing else is touched - passwords, sessions and every other column stay.
 *
 * Deliberately not reversible. A token cannot be put back, and putting back a way to stay signed in is not something a
 * rollback should do; the users it would have restored are signed out once, which is the point of this migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')->whereNotNull('remember_token')->update(['remember_token' => null]);
    }

    public function down(): void
    {
        // Intentionally empty: see the class comment.
    }
};
