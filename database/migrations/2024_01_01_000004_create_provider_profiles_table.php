<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_profiles', function (Blueprint $table) {
            $table->bigIncrements('profile_id');
            $table->unsignedBigInteger('user_id')->unique();
            $table->string('organisation_type', 50);
            $table->string('registration_number', 100);
            $table->string('certificate_path');
            $table->string('certificate_filename');
            $table->dateTime('submitted_at');
            $table->dateTime('reviewed_at')->nullable();
            $table->string('reviewed_by')->nullable();
            $table->string('rejection_reason', 500)->nullable();

            $table->foreign('user_id')->references('user_id')->on('users');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_profiles');
    }
};
