<?php

namespace App\Console\Commands;

use App\GridIndex\ProductGridIndexer;
use Illuminate\Console\Command;

/**
 * Rebuilds the flat product_grid_index from scratch. Run after a bulk
 * raw-DB operation (seeders, manual SQL) that bypasses the model/import
 * sync hooks.
 */
class ReindexProductGrid extends Command
{
    protected $signature = 'unopim:product-grid:reindex
        {--locale= : Only index this locale (faster, for testing)}
        {--chunk=1000 : Products processed per chunk}';

    protected $description = 'Rebuild the flat product grid index for fast datagrid loading.';

    public function handle(ProductGridIndexer $indexer): int
    {
        $locale = $this->option('locale') ?: null;
        $chunk = (int) $this->option('chunk') ?: 1000;

        $this->info('Rebuilding product_grid_index'.($locale ? " (locale={$locale})" : ' (all locales present in data)').'...');

        $start = microtime(true);

        $count = $indexer->reindexAll($locale, $chunk, function (int $done) {
            $this->output->write("\r  Indexed {$done} rows");
        });

        $this->newLine();
        $this->info("Done. {$count} rows in ".round(microtime(true) - $start, 1).'s.');

        return self::SUCCESS;
    }
}
