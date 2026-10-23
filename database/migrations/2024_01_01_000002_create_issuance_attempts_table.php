<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('issuance_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('policy_request_id')->constrained('policy_requests')->onDelete('cascade');
            $table->integer('attempt_number');
            $table->string('scenario', 50)->nullable();
            $table->enum('result', ['SUCCESS', 'FAILURE', 'TIMEOUT', 'PENDING'])->nullable();
            $table->string('provider_reference', 255)->nullable();
            $table->string('policy_number', 100)->nullable();
            $table->json('request_payload')->nullable();
            $table->json('response_payload')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['policy_request_id', 'attempt_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('issuance_attempts');
    }
};
