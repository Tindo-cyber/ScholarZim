<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The award itself, plus the hard eligibility rules ScholarFit uses to
 * disqualify rather than merely down-score.
 *
 * funding_type ("Full", "Partial", …) only ever told a student the shape of the
 * award; these columns tell them what it is worth, how many are on offer, and
 * where the provider's own application lives.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('opportunities', function (Blueprint $table) {
            $table->decimal('award_amount', 12, 2)->nullable()->after('funding_type');
            $table->string('award_currency', 3)->nullable()->after('award_amount');
            $table->unsignedSmallInteger('award_slots')->nullable()->after('award_currency');
            $table->boolean('is_renewable')->default(false)->after('award_slots');
            $table->string('external_url', 500)->nullable()->after('is_renewable');

            // Hard eligibility. NULL means "the provider set no rule", which is
            // never a disqualification - only a set rule the profile fails is.
            $table->unsignedTinyInteger('min_academic_points')->nullable()->after('external_url');
            $table->unsignedTinyInteger('max_age')->nullable()->after('min_academic_points');
            $table->string('required_citizenship', 100)->nullable()->after('max_age');
            $table->string('required_province', 100)->nullable()->after('required_citizenship');
            $table->boolean('requires_results_certificate')->default(false)->after('required_province');

            // Provider funnel analytics: saves and applications are already rows,
            // views are not, so they are counted here.
            $table->unsignedInteger('view_count')->default(0)->after('requires_results_certificate');

            $table->index(['moderation_status', 'status', 'award_amount'], 'idx_opp_award_amount');
        });
    }

    public function down(): void
    {
        Schema::table('opportunities', function (Blueprint $table) {
            $table->dropIndex('idx_opp_award_amount');
            $table->dropColumn([
                'award_amount',
                'award_currency',
                'award_slots',
                'is_renewable',
                'external_url',
                'min_academic_points',
                'max_age',
                'required_citizenship',
                'required_province',
                'requires_results_certificate',
                'view_count',
            ]);
        });
    }
};
