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
        Schema::create('ai_personas', function (Blueprint $table) {
            $table->ulid('id')->primary();
            // NULL user_id = system-level default persona.
            $table->foreignUlid('user_id')
                ->nullable()
                ->constrained()
                ->cascadeOnDelete();
            $table->string('name', 100);
            $table->text('system_prompt');
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false);
            $table->integer('priority')->default(0);
            $table->timestamps();

            $table->index(['user_id', 'is_active']);
            $table->index('is_active');
            $table->index('is_default');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_personas');
    }
};
