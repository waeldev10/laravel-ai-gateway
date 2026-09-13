<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add a nullable JSON holder for per-user UI color customizations.
     *
     * Null (or missing keys) means "use the application defaults" from
     * config/ui.php, so existing users keep the current black/white identity
     * and a reset is a simple null-out rather than a second copy of defaults.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('ui_colors')->nullable()->after('remember_token');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('ui_colors');
        });
    }
};
