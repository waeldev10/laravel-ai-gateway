<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Align `tokenable_id` with the ULID primary keys this project
     * uses (`users.id` is `char(26)`).
     *
     * The stock Sanctum stub used `morphs()`, which creates an
     * unsigned bigint: on MySQL every token insert for a ULID user
     * fails with "Warning: 1265 Data truncated for column
     * 'tokenable_id'". A plain `MODIFY` keeps every row, the
     * `tokenable_type` column, the primary key, and the compound
     * `(tokenable_type, tokenable_id)` index exactly as they are.
     *
     * Raw SQL is used because doctrine/dbal (required for
     * `->change()`) is not installed; on non-MySQL drivers
     * (e.g. sqlite in tests, which does not enforce column types)
     * this is intentionally a no-op.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql'
            || ! Schema::hasTable('personal_access_tokens')
            || ! Schema::hasColumn('personal_access_tokens', 'tokenable_id')) {
            return;
        }

        DB::statement('ALTER TABLE `personal_access_tokens` MODIFY `tokenable_id` CHAR(26) NOT NULL');
    }

    /**
     * Reverse the migrations.
     *
     * Structural reversal only: rows written as ULIDs cannot survive
     * a return to bigint and would truncate again.
     */
    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql'
            || ! Schema::hasTable('personal_access_tokens')
            || ! Schema::hasColumn('personal_access_tokens', 'tokenable_id')) {
            return;
        }

        DB::statement('ALTER TABLE `personal_access_tokens` MODIFY `tokenable_id` BIGINT UNSIGNED NOT NULL');
    }
};
