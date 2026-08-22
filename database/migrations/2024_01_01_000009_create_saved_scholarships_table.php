<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saved_scholarships', function (Blueprint $table) {
            $table->bigIncrements('saved_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('opportunity_id');
            $table->dateTime('saved_at')->nullable();

            $table->unique(['user_id', 'opportunity_id'], 'uk_saved_user_opp');
            $table->foreign('user_id')->references('user_id')->on('users');
            $table->foreign('opportunity_id')->references('opportunity_id')->on('opportunities');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_scholarships');
    }
};
