<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Backing table for SESSION_DRIVER=database.
 *
 * Sessions were files on the container's own disk. Render's free plan attaches
 * no persistent disk and spins a quiet service down, so every deploy and every
 * idle period destroyed every session at once: a signed-in applicant filling in
 * their profile pressed Save and landed on the login page, with the work gone.
 * Nothing about that was visible as an error - the request simply arrived
 * without a session and the auth middleware did its job.
 *
 * A paid plan with a persistent disk would fix the restart case and not the
 * next one: file sessions are local to one container, so the moment the service
 * runs more than a single instance a request handled by the other instance is
 * unauthenticated again. The database is the store both problems share an
 * answer to, and it is already there, already backed up, and already the thing
 * the queue tables use.
 *
 * Laravel's own schema, unchanged. `user_id` is an index rather than a foreign
 * key on purpose - a session row is written before anyone has signed in, and a
 * deleted user's stale rows should expire on their own rather than block the
 * delete.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
    }
};
