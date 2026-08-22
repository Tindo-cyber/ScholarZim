<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->bigIncrements('token_id');
            $table->unsignedBigInteger('user_id');
            $table->string('token')->unique();
            $table->dateTime('expires_at');
            $table->boolean('used')->default(false);

            $table->foreign('user_id')->references('user_id')->on('users');
        });

        Schema::create('email_verification_tokens', function (Blueprint $table) {
            $table->bigIncrements('token_id');
            $table->unsignedBigInteger('user_id');
            $table->string('token', 64)->unique();
            $table->timestamp('expires_at');
            $table->boolean('used')->default(false);

            $table->foreign('user_id')->references('user_id')->on('users');
            $table->index('user_id', 'idx_email_verification_user');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_verification_tokens');
        Schema::dropIfExists('password_reset_tokens');
    }
};
