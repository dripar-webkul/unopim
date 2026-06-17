<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the product image path to the flat index so the default datagrid
 * can render the image column without joining `products` / decoding the
 * 17KB `values` JSON — letting the whole listing be served from the small
 * flat table alone (millisecond loads).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('product_grid_index', 'image')) {
            Schema::table('product_grid_index', function (Blueprint $table) {
                $table->string('image')->nullable()->after('name');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('product_grid_index', 'image')) {
            Schema::table('product_grid_index', function (Blueprint $table) {
                $table->dropColumn('image');
            });
        }
    }
};
