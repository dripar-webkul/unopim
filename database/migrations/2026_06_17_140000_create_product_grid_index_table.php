<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Flat, denormalised index for the product datagrid.
 *
 * One small row per (product, channel, locale) holding the pre-extracted
 * columns the grid sorts / filters / searches on. Because rows are tiny
 * (no 17KB `values` JSON), the (channel, locale, <col>) composite indexes
 * let MySQL satisfy ORDER BY / WHERE with an index range scan instead of a
 * full-scan + filesort over the bloated products table — turning multi-
 * second grid loads into milliseconds, without Elasticsearch.
 *
 * Kept in sync by App\GridIndex\ProductGridIndexer (product save/delete +
 * import batch events) and rebuilt by `unopim:product-grid:reindex`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('product_grid_index')) {
            return;
        }

        Schema::create('product_grid_index', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('product_id');
            $table->string('channel');
            $table->string('locale');

            $table->string('sku');
            $table->string('name')->nullable();
            $table->boolean('status')->default(1);
            $table->string('type')->nullable();
            $table->unsignedInteger('attribute_family_id')->nullable();
            $table->string('attribute_family')->nullable();
            $table->string('parent_sku')->nullable();
            $table->integer('completeness')->nullable();

            // The product's own timestamps (what the grid sorts by).
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();

            // One row per product per context; also used for upsert + delete-by-product.
            $table->unique(['product_id', 'channel', 'locale'], 'pgi_product_channel_locale_unique');
            $table->index('product_id', 'pgi_product_id_idx');

            // Context-scoped sort/filter indexes — the WHERE channel+locale
            // narrows first, then the trailing column gives ordered access.
            $table->index(['channel', 'locale', 'updated_at'], 'pgi_ctx_updated_idx');
            $table->index(['channel', 'locale', 'created_at'], 'pgi_ctx_created_idx');
            $table->index(['channel', 'locale', 'name'], 'pgi_ctx_name_idx');
            $table->index(['channel', 'locale', 'sku'], 'pgi_ctx_sku_idx');
            $table->index(['channel', 'locale', 'completeness'], 'pgi_ctx_completeness_idx');
            $table->index(['channel', 'locale', 'status'], 'pgi_ctx_status_idx');
            $table->index(['channel', 'locale', 'type'], 'pgi_ctx_type_idx');
            $table->index(['channel', 'locale', 'attribute_family_id'], 'pgi_ctx_family_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_grid_index');
    }
};
