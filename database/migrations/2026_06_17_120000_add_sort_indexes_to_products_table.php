<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The product datagrid defaults to ORDER BY products.updated_at and exposes
 * created_at / updated_at as date-range filters and sort columns. Without an
 * index MySQL full-scans + filesorts the whole table, and because the SELECT
 * carries the large `values` JSON, the sort buffer spills to disk (20s+ on a
 * 40k-row catalog). These indexes let the optimizer satisfy the default sort
 * with an index range scan instead. Purely additive — no application change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (! $this->indexExists('products', 'products_updated_at_index')) {
                $table->index('updated_at', 'products_updated_at_index');
            }

            if (! $this->indexExists('products', 'products_created_at_index')) {
                $table->index('created_at', 'products_created_at_index');
            }
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if ($this->indexExists('products', 'products_updated_at_index')) {
                $table->dropIndex('products_updated_at_index');
            }

            if ($this->indexExists('products', 'products_created_at_index')) {
                $table->dropIndex('products_created_at_index');
            }
        });
    }

    private function indexExists(string $table, string $index): bool
    {
        return collect(Schema::getIndexes($table))
            ->contains(fn ($definition) => $definition['name'] === $index);
    }
};
