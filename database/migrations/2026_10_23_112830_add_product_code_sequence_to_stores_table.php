<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->bigInteger('last_product_code')->default(0);
        });

        $connection = Schema::getConnection();

        $connection->table('stores')->orderBy('id')->chunkById(100, function ($stores) use ($connection): void {
            foreach ($stores as $store) {
                $connection->transaction(function () use ($connection, $store): void {
                    $connection->table('stores')->where('id', $store->id)->lockForUpdate()->first();
                    $lastCode = (int) $connection->table('products')->where('store_id', $store->id)->max('import_code');

                    $connection->table('products')->where('store_id', $store->id)->whereNull('import_code')
                        ->orderBy('id')->chunkById(500, function ($products) use ($connection, &$lastCode): void {
                            foreach ($products as $product) {
                                if ($lastCode >= PHP_INT_MAX - 1000) {
                                    throw new RuntimeException('Tovar kodlari chegarasiga yetildi.');
                                }

                                $connection->table('products')->where('id', $product->id)->update(['import_code' => ++$lastCode]);
                            }
                        });

                    $connection->table('stores')->where('id', $store->id)->update(['last_product_code' => $lastCode]);
                });
            }
        });
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn('last_product_code');
        });
    }
};
