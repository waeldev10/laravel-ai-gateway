<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * UI colors are browser-local (localStorage) and must not persist
     * server-side. Drop the legacy column on databases where the earlier
     * add-column migration already ran; fresh databases are unaffected.
     */
    public function up(): void
    {
        if (Schema::hasColumn('users', 'ui_colors')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('ui_colors');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('users', 'ui_colors')) {
            Schema::table('users', function (Blueprint $table) {
                $table->json('ui_colors')->nullable();
            });
        }
    }
};
