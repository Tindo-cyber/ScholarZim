<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The two facts a hard eligibility rule can test that the profile did not yet
 * hold: how old the applicant is, and which citizenship they hold.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applicant_profiles', function (Blueprint $table) {
            $table->date('date_of_birth')->nullable()->after('country');
            $table->string('citizenship', 100)->nullable()->after('date_of_birth');
        });
    }

    public function down(): void
    {
        Schema::table('applicant_profiles', function (Blueprint $table) {
            $table->dropColumn(['date_of_birth', 'citizenship']);
        });
    }
};
