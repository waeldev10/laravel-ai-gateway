<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cover the sidebar/search ordering: ownership scope plus the
     * pinned-first, newest-first sort, so bounded per-group queries and the
     * paginated search never need a filesort over the whole history.
     */
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->index(['user_id', 'pinned_at', 'created_at'], 'conversations_sidebar_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropIndex('conversations_sidebar_idx');
        });
    }
};
