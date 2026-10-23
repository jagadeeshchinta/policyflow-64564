<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_id', 255)->unique(); // Unique event ID for idempotent processing
            $table->foreignId('policy_request_id')->constrained('policy_requests')->onDelete('cascade');
            $table->string('event_type', 50); // e.g., 'POLICY_ISSUED', 'POLICY_FAILED'
            $table->string('provider_reference', 255)->nullable();
            $table->string('policy_number', 100)->nullable();
            $table->json('payload')->nullable();
            $table->boolean('processed')->default(false);
            $table->timestamp('processed_at')->nullable();
            $table->text('processing_notes')->nullable();
            $table->timestamps();

            $table->index(['policy_request_id', 'event_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_events');
    }
};
