<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The fields table now holds the whole of ISCED-F 2013 - broad (2 digits), narrow (3) and detailed (4) fields, with the
 * supplementary "not further defined", "inter-disciplinary" and "not elsewhere classified" codes UNESCO defines - not only
 * the broad and narrow fields it started with.
 *
 * STRUCTURE ONLY. This adds columns and describes the rows already there; it does not add, move, rename or delete a field, so
 * every existing id (and every programme, scholarship and saved choice that points at one) is untouched. The new fields are
 * loaded afterwards by the importer (`php artisan catalogue:import`), which is repeatable and adds only what is missing.
 *
 *   level          broad | narrow | detailed - the official level, which is also the length of the code (2, 3 or 4).
 *   kind           official (a field in the classification proper) or one of the supplementary codes UNESCO describes:
 *                  not_further_defined (ends 0), interdisciplinary (ends 8), not_elsewhere_classified (ends 9).
 *   is_selectable  whether a provider may choose it as a target. A parent that gathers materially different disciplines
 *                  (Business, administration AND law) is a heading to find things under, not a choice; the fields inside it are.
 *   is_active      switched off, a field is no longer offered for new choices but everything that already refers to it still does.
 *   synonyms       other words people search by ("accounting", "LLB", "nursing"), as a JSON list.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fields', function (Blueprint $table) {
            $table->string('level', 10)->default('narrow')->after('parent_id');
            $table->string('kind', 30)->default('official')->after('level');
            $table->boolean('is_selectable')->default(true)->after('kind');
            $table->boolean('is_active')->default(true)->after('is_selectable');
            $table->json('synonyms')->nullable()->after('display_name');

            $table->index(['level', 'is_active']);
            $table->index('kind');
        });

        // Describe what is already there. Broad fields have no parent; the level is the length of the code.
        foreach (DB::table('fields')->get(['id', 'code', 'parent_id']) as $field) {
            $length = strlen((string) $field->code);
            $level = match (true) {
                $length <= 2 => 'broad',
                $length === 3 => 'narrow',
                default => 'detailed',
            };

            DB::table('fields')->where('id', $field->id)->update(['level' => $level]);
        }

        // A broad field is a choice in its own right only when it holds one discipline: a single narrow field (Education, ICT).
        // The others gather different disciplines and become headings; anything already saved against them keeps working.
        $broads = DB::table('fields')->where('level', 'broad')->pluck('id', 'code');

        foreach ($broads as $code => $id) {
            $narrow = DB::table('fields')->where('parent_id', $id)->count();

            DB::table('fields')->where('id', $id)->update(['is_selectable' => $narrow === 1]);
        }
    }

    public function down(): void
    {
        Schema::table('fields', function (Blueprint $table) {
            $table->dropIndex(['level', 'is_active']);
            $table->dropIndex(['kind']);
            $table->dropColumn(['level', 'kind', 'is_selectable', 'is_active', 'synonyms']);
        });
    }
};
