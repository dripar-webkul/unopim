<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Speeds up the product datagrid's sortable columns on a large catalog
 * WITHOUT Elasticsearch and WITHOUT touching application code.
 *
 * Two transparent, optimizer-level fixes:
 *
 *  1. avg_completeness_score index — the "completeness" column is sortable;
 *     without an index MySQL full-scans + filesorts the whole table while
 *     carrying the ~17KB `values` JSON in the sort buffer (~23s).
 *
 *  2. grid_name_default_en — a VIRTUAL generated column holding the
 *     lower-cased product name for the default channel/locale, with an
 *     index. The datagrid sorts name with exactly:
 *       LOWER(JSON_UNQUOTE(JSON_EXTRACT(`values`,'$.channel_locale_specific.default.en_US.name')))
 *     MySQL's generated-column substitution makes the optimizer use this
 *     index for that ORDER BY automatically (~35s -> instant). The column
 *     is read-only and auto-maintained by MySQL whenever `values` changes,
 *     so no application/insert path changes.
 *
 *  Note: the generated column covers the DEFAULT channel + locale only
 *  (the common grid context). Other contexts and free-text "contains"
 *  searches still scan — those need Elasticsearch or a flat index table.
 */
return new class extends Migration
{
    private string $nameExpr = "LOWER(JSON_UNQUOTE(JSON_EXTRACT(`values`,'\$.channel_locale_specific.default.en_US.name')))";

    public function up(): void
    {
        if (! $this->indexExists('products', 'products_avg_completeness_score_index')) {
            Schema::table('products', function ($table) {
                $table->index('avg_completeness_score', 'products_avg_completeness_score_index');
            });
        }

        if (! $this->columnExists('products', 'grid_name_default_en')) {
            DB::statement(
                "ALTER TABLE `products`
                 ADD COLUMN `grid_name_default_en` VARCHAR(255)
                 GENERATED ALWAYS AS ({$this->nameExpr}) VIRTUAL"
            );
        }

        if (! $this->indexExists('products', 'products_grid_name_default_en_idx')) {
            DB::statement(
                'ALTER TABLE `products`
                 ADD INDEX `products_grid_name_default_en_idx` (`grid_name_default_en`)'
            );
        }
    }

    public function down(): void
    {
        if ($this->indexExists('products', 'products_grid_name_default_en_idx')) {
            DB::statement('ALTER TABLE `products` DROP INDEX `products_grid_name_default_en_idx`');
        }

        if ($this->columnExists('products', 'grid_name_default_en')) {
            DB::statement('ALTER TABLE `products` DROP COLUMN `grid_name_default_en`');
        }

        if ($this->indexExists('products', 'products_avg_completeness_score_index')) {
            Schema::table('products', function ($table) {
                $table->dropIndex('products_avg_completeness_score_index');
            });
        }
    }

    private function indexExists(string $table, string $index): bool
    {
        return collect(Schema::getIndexes($table))->contains(fn ($d) => $d['name'] === $index);
    }

    private function columnExists(string $table, string $column): bool
    {
        return Schema::hasColumn($table, $column);
    }
};
