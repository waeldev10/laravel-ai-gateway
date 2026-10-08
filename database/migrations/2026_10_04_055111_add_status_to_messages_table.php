<?php

use App\Enums\MessageStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Track whether an assistant message finished generating.
     *
     * `complete` (default, backfills existing rows) versus `partial`
     * (generation was stopped before completion and may be continued).
     * User messages are always `complete`.
     */
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->string('status')->default(MessageStatus::Complete->value)->after('content');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn('status');
        });
    }
};
