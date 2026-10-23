<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('policy_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('client_request_id', 100);
            $table->string('agent_id', 100);
            $table->string('customer_name', 255);
            $table->date('date_of_birth');
            $table->enum('destination', ['DOMESTIC', 'INTERNATIONAL']);
            $table->date('trip_start');
            $table->date('trip_end');
            $table->enum('plan_code', ['BASIC', 'PLUS', 'PREMIUM']);
            $table->decimal('sum_insured', 12, 2);
            $table->decimal('calculated_premium', 12, 2)->nullable();
            $table->integer('travel_days')->nullable();
            $table->decimal('age_multiplier', 5, 2)->nullable();
            $table->decimal('destination_multiplier', 5, 2)->nullable();
            $table->decimal('coverage_multiplier', 5, 2)->nullable();
            $table->decimal('base_daily_rate', 8, 2)->nullable();
            $table->enum('status', [
                'RECEIVED',
                'VALIDATED',
                'SUBMITTED',
                'PENDING_CONFIRMATION',
                'ISSUED',
                'FAILED',
                'NEEDS_RECONCILIATION',
            ])->default('RECEIVED');
            $table->string('policy_number', 100)->nullable()->unique();
            $table->string('provider_reference', 255)->nullable();
            $table->integer('attempt_count')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamps();

            // Enforce uniqueness of agent_id + client_request_id at database level
            $table->unique(['agent_id', 'client_request_id'], 'unique_agent_client_request');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('policy_requests');
    }
};
