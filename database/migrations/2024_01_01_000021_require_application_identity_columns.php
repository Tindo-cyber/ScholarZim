<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Makes the two columns that identify an application NOT NULL.
 *
 * uk_applications_user_opportunity already exists and is what stops two
 * simultaneous submissions both becoming applications - the submission path
 * relies on the database refusing the loser rather than on a check that two
 * requests can pass at the same moment. That defence is only total while both
 * columns are non-null: SQL treats NULLs as distinct in a unique index, so rows
 * with a missing user_id or opportunity_id sit outside the constraint entirely
 * and could be inserted without limit.
 *
 * Nothing in the application writes such a row - submit() always sets both, and
 * both carry foreign keys - so this closes a hole rather than changing any
 * behaviour. If a legacy row does hold a NULL the ALTER fails loudly, which is
 * the right outcome: an application belonging to no one is not something to
 * quietly repair inside a migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable(false)->change();
            $table->unsignedBigInteger('opportunity_id')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable()->change();
            $table->unsignedBigInteger('opportunity_id')->nullable()->change();
        });
    }
};
