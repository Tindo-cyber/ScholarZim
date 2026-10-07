<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two additive columns on a listing.
 *
 * on_behalf_of: provider_name used to hold whatever the provider typed as the
 * "awarding body", with the other providers' names offered as suggestions, so
 * anyone could publish under another organisation's name. provider_name now
 * always holds the verified organisation's own name; a different name goes
 * here, and is shown as "Posted by [organisation] on behalf of [name]".
 *
 * risk_flags: the reasons a moderator should look at a listing twice, as a list
 * of {code, message}. Null when there are none. Existing rows have none, which
 * is accurate: nothing has been checked yet.
 *
 * Both are nullable and nothing is rewritten, so the migration is safe to run
 * on a live table and reverses by dropping the columns it added.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('opportunities', function (Blueprint $table) {
            $table->string('on_behalf_of', 255)->nullable();
            $table->json('risk_flags')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('opportunities', function (Blueprint $table) {
            $table->dropColumn(['on_behalf_of', 'risk_flags']);
        });
    }
};
