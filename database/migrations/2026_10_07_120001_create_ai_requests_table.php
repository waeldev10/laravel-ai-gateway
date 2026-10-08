<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('ai_requests', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->string('source', 20)->default('web');
            $table->string('provider', 50);
            $table->string('model')->nullable();
            $table->foreignUlid('conversation_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();
            $table->foreignUlid('message_id')
                ->nullable()
                ->constrained('messages')
                ->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('status', 20)->default('started');
            $table->unsignedInteger('duration_ms')->nullable();
            $table->unsignedInteger('retry_count')->default(0);
            $table->string('provider_request_id')->nullable();
            $table->string('error_type')->nullable();
            $table->string('error_code')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index('conversation_id');
            $table->index('status');
            $table->index('source');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_requests');
    }
};
