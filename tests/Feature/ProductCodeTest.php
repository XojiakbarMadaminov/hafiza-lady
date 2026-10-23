<?php

use App\Models\User;
use App\Models\Store;
use App\Models\Product;
use Illuminate\Validation\ValidationException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->store = Store::query()->create([
        'name'    => 'Product code store',
        'address' => 'Tashkent',
        'phone'   => '+998901234567',
    ]);
});

it('automatically assigns codes after the largest existing code within each store', function () {
    $imported     = Product::factory()->create(['store_id' => $this->store->id, 'import_code' => 501]);
    $manual       = Product::factory()->create(['store_id' => $this->store->id]);
    $otherStore   = Store::query()->create(['name' => 'Other store', 'address' => 'Tashkent', 'phone' => '123']);
    $otherProduct = Product::factory()->create(['store_id' => $otherStore->id]);

    expect($imported->import_code)->toBe(501)
        ->and($manual->import_code)->toBe(502)
        ->and($otherProduct->import_code)->toBe(1)
        ->and($this->store->fresh()->last_product_code)->toBe(502)
        ->and($manual->display_label)->toContain('Kod: 502');
});

it('never automatically reuses codes after soft or permanent deletion', function (bool $permanent) {
    $product = Product::factory()->create(['store_id' => $this->store->id, 'import_code' => 80]);

    if ($permanent) {
        $product->forceDelete();
    } else {
        $product->delete();
    }

    expect(Product::factory()->create(['store_id' => $this->store->id])->import_code)->toBe(81);
})->with([false, true]);

it('rejects duplicate codes including soft deleted products', function (bool $deleted) {
    $product = Product::factory()->create(['store_id' => $this->store->id, 'import_code' => 80]);

    if ($deleted) {
        $product->delete();
    }

    expect(fn () => Product::factory()->create(['store_id' => $this->store->id, 'import_code' => 80]))
        ->toThrow(ValidationException::class);
})->with([false, true]);

it('keeps the code unchanged when editing and restoring a product', function () {
    $product = Product::factory()->create(['store_id' => $this->store->id]);
    $code    = $product->import_code;
    $product->update(['name' => 'Updated product']);
    $product->delete();
    $product->restore();

    expect($product->fresh()->import_code)->toBe($code);
    expect(fn () => $product->update(['import_code' => $code + 1]))->toThrow(ValidationException::class);
    expect($product->fresh()->import_code)->toBe($code);
});

it('rolls back the sequence together with a failed product transaction', function () {
    try {
        $this->store->getConnection()->transaction(function (): void {
            Product::factory()->create(['store_id' => $this->store->id, 'import_code' => 500]);

            throw new RuntimeException('Rollback');
        });
    } catch (RuntimeException) {
    }

    expect(Product::factory()->create(['store_id' => $this->store->id])->import_code)->toBe(1);
});

it('backfills missing codes without changing imported codes ids or other stores', function () {
    $imported = Product::factory()->create(['store_id' => $this->store->id, 'import_code' => 80]);
    $legacy   = Product::factory()->create(['store_id' => $this->store->id]);
    $deleted  = Product::factory()->create(['store_id' => $this->store->id]);
    $deleted->delete();
    Product::withoutGlobalScopes()->whereKey([$legacy->id, $deleted->id])->update(['import_code' => null]);
    $otherStore = Store::query()->create(['name' => 'Other store', 'address' => 'Tashkent', 'phone' => '123']);
    $other      = Product::factory()->create(['store_id' => $otherStore->id, 'import_code' => 900]);
    $this->actingAs(User::factory()->create(['current_store_id' => $this->store->id]));
    $migration = require database_path('migrations/2026_10_23_112830_add_product_code_sequence_to_stores_table.php');
    $migration->down();
    $migration->up();

    expect($imported->fresh()->import_code)->toBe(80)
        ->and($legacy->fresh()->import_code)->toBe(81)
        ->and(Product::withoutGlobalScopes()->findOrFail($deleted->id)->import_code)->toBe(82)
        ->and($other->fresh()->import_code)->toBe(900)
        ->and($this->store->fresh()->last_product_code)->toBe(82)
        ->and(Product::factory()->create(['store_id' => $this->store->id])->import_code)->toBe(83);
});

it('parses only valid positive product codes without integer overflow', function (string $value, ?int $expected) {
    expect(Product::parseCode($value))->toBe($expected);
})->with([
    ['101', 101], [' 00101 ', 101], ['', null], ['0', null], ['-1', null],
    ['1.5', null], ['1e2', null], ['abc', null], ['999999999999999999999', null],
]);
