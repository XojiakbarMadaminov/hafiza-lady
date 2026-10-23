<?php

use App\Models\User;
use App\Models\Stock;
use App\Models\Store;
use Livewire\Livewire;
use App\Models\Product;
use App\Filament\Pages\Pos;
use App\Models\ProductStock;
use Filament\Facades\Filament;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Database\Eloquent\ModelNotFoundException;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['app.key' => 'base64:' . base64_encode(str_repeat('a', 32))]);
    $this->store = Store::query()->create([
        'name'    => 'Variant store',
        'address' => 'Tashkent',
        'phone'   => '+998901234567',
    ]);
    $this->stock = Stock::query()->create([
        'name'      => 'Main stock',
        'is_main'   => true,
        'is_active' => true,
    ]);
    $this->store->stocks()->attach($this->stock);
    $this->user = User::factory()->create(['current_store_id' => $this->store->id]);
    $this->user->stores()->attach($this->store);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    Permission::findOrCreate('View:Pos', 'web');
    $this->user->givePermissionTo('View:Pos');
    $this->actingAs($this->user);
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $this->product = Product::factory()->create([
        'store_id'      => $this->store->id,
        'name'          => 'Variant product',
        'type'          => Product::TYPE_SIZE,
        'initial_price' => 50000,
        'price'         => 99000,
    ]);
    $this->firstVariant  = $this->product->sizes()->create(['size' => '36']);
    $this->secondVariant = $this->product->sizes()->create(['size' => '37']);
    foreach ([$this->firstVariant, $this->secondVariant] as $variant) {
        ProductStock::query()->create([
            'product_size_id' => $variant->id,
            'stock_id'        => $this->stock->id,
            'quantity'        => 2,
        ]);
    }
});

it('opens size choices only after pressing the variant toggle', function (string $method) {
    Livewire::test(Pos::class)
        ->call($method, (string) ($method === 'addByBarcode' ? $this->product->barcode : ($method === 'add' ? $this->product->id : $this->product->import_code)))
        ->assertSet('cart', [])
        ->assertSeeHtml('data-testid="variant-toggle-' . $this->product->id . '"')
        ->assertDontSeeHtml('data-testid="variant-picker-' . $this->product->id . '"')
        ->call('toggleVariantPicker', $this->product->id)
        ->assertSeeHtml('data-testid="variant-picker-' . $this->product->id . '"')
        ->assertSee('Razmerni tanlang:')
        ->assertDontSeeHtml('open-size-modal')
        ->assertSet('cart', [])
        ->call('toggleVariantPicker', $this->product->id)
        ->assertDontSeeHtml('data-testid="variant-picker-' . $this->product->id . '"');
})->with(['addByProductCode', 'addByBarcode', 'add']);

it('shows inline color choices in search results', function () {
    $this->product->update(['type' => Product::TYPE_COLOR]);
    $this->firstVariant->update(['size' => 'Qizil']);

    Livewire::test(Pos::class)
        ->set('search', 'Variant')
        ->assertDontSee('Rangni tanlang:')
        ->call('toggleVariantPicker', $this->product->id)
        ->assertSee('Rangni tanlang:')
        ->assertSee('Qizil')
        ->call('addVariant', $this->product->id, $this->firstVariant->id)
        ->assertSeeHtml('data-testid="cart-line-' . $this->product->id . '-' . $this->firstVariant->id . '"');
});

it('closes variant choices when searching for another product', function (string $field, string $value) {
    Livewire::test(Pos::class)
        ->set('search', 'Variant')
        ->call('toggleVariantPicker', $this->product->id)
        ->assertSet('expandedVariantProductId', $this->product->id)
        ->set($field, $value)
        ->assertSet('expandedVariantProductId', null)
        ->assertDontSeeHtml('data-testid="variant-picker-' . $this->product->id . '"');
})->with([
    'name search' => ['search', 'other'],
    'id search'   => ['searchByCode', '9999'],
]);

it('adds each selected variant as a separate cart line and updates totals', function () {
    $component = Livewire::test(Pos::class)
        ->call('addVariant', $this->product->id, $this->firstVariant->id)
        ->call('addVariant', $this->product->id, $this->firstVariant->id)
        ->call('addVariant', $this->product->id, $this->secondVariant->id)
        ->assertSet('cart.' . $this->product->id . '.sizes.' . $this->firstVariant->id, 2)
        ->assertSet('cart.' . $this->product->id . '.sizes.' . $this->secondVariant->id, 1)
        ->assertSet('totals.qty', 3)
        ->assertSet('totals.amount', 297000.0)
        ->assertSeeHtml('data-testid="cart-line-' . $this->product->id . '-' . $this->firstVariant->id . '"')
        ->assertSeeHtml('data-testid="cart-line-' . $this->product->id . '-' . $this->secondVariant->id . '"')
        ->assertDontSee('Kelgan narxi');

    expect($component->instance()->cartLines)->toHaveCount(2);

    $component->assertSeeHtmlInOrder([
        'data-testid="quantity-decrease-1-' . $this->product->id . '-' . $this->firstVariant->id . '"',
        'aria-hidden="true">−</span>',
        'aria-label="Variant product 36 miqdori"',
        'data-testid="quantity-increase-1-' . $this->product->id . '-' . $this->firstVariant->id . '"',
        'aria-hidden="true">+</span>',
    ])
        ->call('updateVariantQty', $this->product->id, $this->firstVariant->id, 1)
        ->assertSet('totals.qty', 2)
        ->assertSet('totals.amount', 198000.0);
});

it('removes only the selected variant and removes the product after its final variant', function () {
    Livewire::test(Pos::class)
        ->call('addVariant', $this->product->id, $this->firstVariant->id)
        ->call('addVariant', $this->product->id, $this->secondVariant->id)
        ->call('updateVariantQty', $this->product->id, $this->firstVariant->id, 0)
        ->assertDontSeeHtml('data-testid="cart-line-' . $this->product->id . '-' . $this->firstVariant->id . '"')
        ->assertSet('cart.' . $this->product->id . '.sizes', [$this->secondVariant->id => 1])
        ->assertSet('totals.qty', 1)
        ->call('updateVariantQty', $this->product->id, $this->secondVariant->id, 0)
        ->assertSet('cart', [])
        ->assertSet('totals.qty', 0);
});

it('rejects quantities outside variant stock limits', function (int $quantity) {
    Livewire::test(Pos::class)
        ->call('addVariant', $this->product->id, $this->firstVariant->id)
        ->call('updateVariantQty', $this->product->id, $this->firstVariant->id, $quantity)
        ->assertNotified('Yetarli miqdor yo‘q')
        ->assertSet('cart.' . $this->product->id . '.sizes.' . $this->firstVariant->id, 1);
})->with([-1, 3]);

it('does not add an out of stock variant', function () {
    $this->firstVariant->productStocks()->update(['quantity' => 0]);

    Livewire::test(Pos::class)
        ->call('addVariant', $this->product->id, $this->firstVariant->id)
        ->assertNotified('Yetarli miqdor yo‘q')
        ->assertSet('cart', []);
});

it('rejects variants belonging to another product', function () {
    $otherProduct = Product::factory()->create(['store_id' => $this->store->id, 'type' => Product::TYPE_SIZE]);
    $otherVariant = $otherProduct->sizes()->create(['size' => '40']);

    $component = Livewire::test(Pos::class);

    expect(fn () => $component->call('addVariant', $this->product->id, $otherVariant->id))
        ->toThrow(ModelNotFoundException::class);
    expect(session('pos_carts.1', []))->toBeEmpty();
});

it('uses the selected warehouse when checking variant quantities', function () {
    $secondStock = Stock::query()->create(['name' => 'Second stock', 'is_active' => true]);
    $this->store->stocks()->attach($secondStock);
    $this->firstVariant->productStocks()->create(['stock_id' => $secondStock->id, 'quantity' => 5]);

    $component = Livewire::test(Pos::class)
        ->call('addVariant', $this->product->id, $this->firstVariant->id)
        ->call('updateStock', $this->product->id, $secondStock->id)
        ->call('updateVariantQty', $this->product->id, $this->firstVariant->id, 5)
        ->assertSet('cart.' . $this->product->id . '.stock_id', $secondStock->id)
        ->assertSet('cart.' . $this->product->id . '.qty', 5);

    expect($component->instance()->cartLines[0]['available'])->toBe(5);
});

it('keeps variant selections isolated between active carts', function () {
    Livewire::test(Pos::class)
        ->call('addVariant', $this->product->id, $this->firstVariant->id)
        ->call('createNewCart')
        ->assertSet('cart', [])
        ->call('addVariant', $this->product->id, $this->secondVariant->id)
        ->call('switchCart', 1)
        ->assertSet('cart.' . $this->product->id . '.sizes', [$this->firstVariant->id => 1]);
});

it('prevents variant access after POS permission is revoked', function () {
    $component = Livewire::test(Pos::class);
    $this->user->revokePermissionTo('View:Pos');

    $component->call('addVariant', $this->product->id, $this->firstVariant->id)
        ->assertForbidden();
});

it('shows package quantity controls without purchase price or variant column', function () {
    $this->product->update(['type' => Product::TYPE_PACKAGE]);
    ProductStock::query()->create([
        'product_id' => $this->product->id,
        'stock_id'   => $this->stock->id,
        'quantity'   => 10,
    ]);

    Livewire::test(Pos::class)
        ->call('addByProductCode', (string) $this->product->import_code)
        ->assertSee('Miqdorni oshirish')
        ->assertSee('Miqdorni kamaytirish')
        ->assertSeeHtmlInOrder([
            'data-testid="quantity-decrease-1-' . $this->product->id . '-package"',
            'aria-hidden="true">−</span>',
            'aria-label="Variant product  miqdori"',
            'data-testid="quantity-increase-1-' . $this->product->id . '-package"',
            'aria-hidden="true">+</span>',
        ])
        ->assertDontSee('Kelgan narxi')
        ->assertDontSee('Razmer / Rang')
        ->call('updatePackageQty', $this->product->id, 2)
        ->assertSet('totals.qty', 2)
        ->call('updatePackageQty', $this->product->id, 1)
        ->assertSet('totals.qty', 1);
});
