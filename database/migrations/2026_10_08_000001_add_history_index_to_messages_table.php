<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Index the cursor-pagination access pattern for message history:
     * WHERE conversation_id = ? AND id < ? ORDER BY id DESC LIMIT n.
     *
     * The foreign key already indexes conversation_id alone; the composite
     * index additionally covers the ULID ordering inside one conversation
     * so history pages never need a filesort over the whole thread.
     */
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->index(['conversation_id', 'id'], 'messages_conversation_id_id_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropIndex('messages_conversation_id_id_index');
        });
    }
};
