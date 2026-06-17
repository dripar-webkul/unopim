<?php

namespace App\Providers;

use App\Console\Commands\ReindexProductGrid;
use App\GridIndex\FlatProductDataGrid;
use App\GridIndex\ProductGridIndexer;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Webkul\Admin\DataGrids\Catalog\ProductDataGrid;
use Webkul\Product\Models\ProductProxy;

/**
 * Wires up the flat product grid index:
 *  - serves the product datagrid from FlatProductDataGrid (fast path),
 *  - keeps product_grid_index in sync on single saves/deletes and imports,
 *  - registers the rebuild command.
 *
 * Pure infrastructure: no business logic or existing query is altered.
 */
class GridIndexServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Controller resolves the grid via app(ProductDataGrid::class); swap in
        // the flat-index implementation transparently.
        $this->app->bind(ProductDataGrid::class, FlatProductDataGrid::class);

        $this->commands([ReindexProductGrid::class]);
    }

    public function boot(): void
    {
        // Single product create/update/delete (admin form, API, repository).
        ProductProxy::saved(function ($product) {
            app(ProductGridIndexer::class)->reindexProduct((int) $product->id);
        });

        ProductProxy::deleted(function ($product) {
            app(ProductGridIndexer::class)->removeProduct((int) $product->id);
        });

        // Bulk product imports write via raw DB (no model events); the importer
        // dispatches this with the affected product ids after each batch.
        Event::listen('data_transfer.imports.batch.product.save.after', function ($payload) {
            $ids = $payload['product_id'] ?? $payload;
            if (! empty($ids)) {
                app(ProductGridIndexer::class)->reindexProducts(array_map('intval', (array) $ids));
            }
        });
    }
}
