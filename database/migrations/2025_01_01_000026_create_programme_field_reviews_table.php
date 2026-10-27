<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a proposed move of a programme to a more specific field waits for a person to confirm it.
 *
 * Structure only: nothing here changes a programme. `catalogue:reclassify` writes a row for every change it will not make on
 * its own (a move into another narrow field, or a refinement it is not sure of), and an administrator accepts or dismisses it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('programme_field_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('programme_id')->constrained('programmes')->cascadeOnDelete();
            $table->foreignId('from_field_id')->nullable()->constrained('fields')->restrictOnDelete();
            $table->foreignId('to_field_id')->constrained('fields')->restrictOnDelete();
            $table->string('kind', 10);
            $table->string('confidence', 10);
            $table->string('reason', 500);
            $table->string('status', 10)->default('pending');
            $table->string('decided_by', 255)->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->unique(['programme_id', 'to_field_id']);
            $table->index(['status', 'confidence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('programme_field_reviews');
    }
};
