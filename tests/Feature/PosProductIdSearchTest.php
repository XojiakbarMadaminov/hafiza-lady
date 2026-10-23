<?php

use Tests\TestCase;
use App\Models\Sale;
use App\Models\User;
use App\Models\Stock;
use App\Models\Store;
use App\Models\Client;
use App\Models\Debtor;
use Livewire\Livewire;
use App\Models\Product;
use App\Filament\Pages\Pos;
use App\Models\ProductStock;
use Filament\Facades\Filament;
use App\Models\DebtorTransaction;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * @return array{0: Product, 1: User}
 */
function createVisiblePosProduct(TestCase $testCase, array $attributes = []): array
{
    $store = Store::create([
        'name'    => fake()->company(),
        'address' => fake()->address(),
        'phone'   => fake()->phoneNumber(),
    ]);

    $stock = Stock::create([
        'name'      => 'Main stock',
        'is_main'   => true,
        'is_active' => true,
    ]);

    $store->stocks()->attach($stock);

    $user = User::factory()->create([
        'current_store_id' => $store->id,
    ]);

    $user->stores()->attach($store);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    Permission::firstOrCreate([
        'name'       => 'View:Pos',
        'guard_name' => 'web',
    ]);
    $user->givePermissionTo('View:Pos');

    $testCase->actingAs($user);
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $product = Product::factory()->create(array_merge([
        'store_id' => $store->id,
        'type'     => Product::TYPE_PACKAGE,
    ], $attributes));

    ProductStock::create([
        'product_id' => $product->id,
        'stock_id'   => $stock->id,
        'quantity'   => 10,
    ]);

    return [$product, $user];
}

it('searches products by code on the POS page', function () {
    [$product, $user] = createVisiblePosProduct($this, [
        'name'        => 'Kod orqali topiladigan tovar',
        'import_code' => 701,
    ]);

    Product::factory()->create([
        'name'     => 'Boshqa tovar',
        'store_id' => $product->store_id,
    ]);

    Livewire::actingAs($user)
        ->test(Pos::class)
        ->set('searchByCode', (string) $product->import_code)
        ->assertSet('search', '')
        ->assertSee('Kod orqali topiladigan tovar')
        ->assertSee('Tovar kodi')
        ->assertDontSee('Tovar ID si orqali qidiring...')
        ->assertDontSee('Boshqa tovar');
});

it('adds a product to the POS cart by code while retaining its internal id', function () {
    [$product, $user] = createVisiblePosProduct($this, [
        'name'        => 'ID orqali savatga tushadigan tovar',
        'price'       => 125000,
        'import_code' => 702,
    ]);

    Livewire::actingAs($user)
        ->test(Pos::class)
        ->call('addByProductCode', (string) $product->import_code)
        ->assertSet('searchByCode', '')
        ->assertSee('ID orqali savatga tushadigan tovar');

    expect(session('pos_carts.1.' . $product->id))
        ->not->toBeNull()
        ->toMatchArray([
            'id'   => $product->id,
            'name' => 'ID orqali savatga tushadigan tovar',
            'qty'  => 1,
        ]);
});

it('does not confuse a product code with another products database id', function () {
    [$product, $user] = createVisiblePosProduct($this, ['import_code' => 701, 'name' => 'Correct code product']);
    $other            = Product::factory()->create([
        'id'          => 701,
        'store_id'    => $product->store_id,
        'import_code' => 702,
        'name'        => 'Wrong database id product',
    ]);

    Livewire::actingAs($user)->test(Pos::class)
        ->set('searchByCode', '701')
        ->assertSee('Correct code product')
        ->assertDontSee('Wrong database id product')
        ->call('addByProductCode', '701');

    expect(session('pos_carts.1.' . $product->id . '.id'))->toBe($product->id)
        ->and(session('pos_carts.1.' . $other->id))->toBeNull();
});

it('never falls back to a database id when a code is missing', function () {
    [$product, $user] = createVisiblePosProduct($this, ['import_code' => 701]);

    Livewire::actingAs($user)->test(Pos::class)
        ->set('searchByCode', (string) $product->id)
        ->assertSet('products', fn ($products): bool => $products->isEmpty())
        ->call('addByProductCode', (string) $product->id)
        ->assertNotified('Mahsulot topilmadi');

    expect(session('pos_carts.1.' . $product->id))->toBeNull();
});

it('searches return and exchange products by code and labels them with the code', function (string $field, string $options) {
    [$product, $user] = createVisiblePosProduct($this, ['import_code' => 701]);

    Livewire::actingAs($user)->test(Pos::class)
        ->set($field, '701')
        ->assertSet($options, fn (array $results): bool => count($results) === 1
            && isset($results[$product->id]) && str_contains($results[$product->id], 'Kod: 701'));
})->with([
    ['returnProductSearch', 'returnProductOptions'],
    ['exchangeInProductSearch', 'exchangeInProductOptions'],
    ['exchangeOutProductSearch', 'exchangeOutProductOptions'],
]);

it('limits code searches and cart additions to the current store and active products', function (bool $deleted) {
    [$product, $user] = createVisiblePosProduct($this, ['import_code' => 701]);

    if ($deleted) {
        $product->delete();
    } else {
        $otherStore = Store::query()->create(['name' => 'Other store', 'address' => 'Tashkent', 'phone' => '123']);
        $user->update(['current_store_id' => $otherStore->id]);
    }

    Livewire::actingAs($user)->test(Pos::class)
        ->set('searchByCode', '701')
        ->assertSet('products', fn ($products): bool => $products->isEmpty())
        ->call('addByProductCode', '701')
        ->assertNotified('Mahsulot topilmadi');

    expect(session('pos_carts.1.' . $product->id))->toBeNull();
})->with([false, true]);

it('handles invalid or oversized code input without adding products', function (string $input) {
    [$product, $user] = createVisiblePosProduct($this, ['import_code' => 701]);

    Livewire::actingAs($user)->test(Pos::class)
        ->set('searchByCode', $input)
        ->assertSet('products', fn ($products): bool => $products->isEmpty())
        ->call('addByProductCode', $input);

    expect(session('pos_carts.1.' . $product->id))->toBeNull();
})->with(['', '0', '-1', '1.5', 'abc', '9999999999999999999999999999']);

it('does not search across stores when the user has no current store', function () {
    [$product, $user] = createVisiblePosProduct($this, ['import_code' => 701]);
    $user->update(['current_store_id' => null]);

    Livewire::actingAs($user)->test(Pos::class)
        ->set('searchByCode', '701')
        ->assertSet('products', fn ($products): bool => $products->isEmpty())
        ->call('addByProductCode', '701')
        ->set('returnProductSearch', '701')
        ->assertSet('returnProductOptions', []);

    expect(session('pos_carts.1.' . $product->id))->toBeNull();
});

it('applies and persists a customer discount during checkout', function () {
    [$product, $user] = createVisiblePosProduct($this, [
        'name'  => 'Chegirmali tovar',
        'price' => 200000,
    ]);
    $client = Client::factory()->create();

    Livewire::actingAs($user)
        ->test(Pos::class)
        ->call('addByProductCode', (string) $product->import_code)
        ->call('selectClient', $client->id)
        ->set('customerDiscountType', 'percent')
        ->set('customerDiscountValue', 10)
        ->assertSet('totals.customer_discount_amount', 20000.0)
        ->assertSet('totals.amount', 180000.0)
        ->call('selectPaymentType', 'mixed')
        ->set('mixedPayment.card', 50000)
        ->assertSet('mixedPayment.cash', 130000.0)
        ->call('selectPaymentType', 'cash')
        ->call('checkout')
        ->assertSet('selectedClientId', null)
        ->assertSet('saleWithoutClient', true)
        ->assertSet('saleWithoutClientPaymentType', 'cash');

    $sale = Sale::query()->latest('id')->firstOrFail();

    expect($sale->client_id)->toBe($client->id)
        ->and($sale->customer_discount_type)->toBe('percent')
        ->and($sale->customer_discount_value)->toBe(10.0)
        ->and($sale->customer_discount_amount)->toBe(20_000.0)
        ->and($sale->total_amount)->toBe(180_000.0)
        ->and($sale->paid_amount)->toBe(180_000.0)
        ->and($sale->remaining_amount)->toBe(0.0)
        ->and(session('pos_cart_customer_discounts.1'))->toBeNull();
});

it('allows a fully discounted customer sale with a zero final total', function () {
    [$product, $user] = createVisiblePosProduct($this, [
        'name'  => 'Bepul qilinadigan tovar',
        'price' => 100000,
    ]);
    $client = Client::factory()->create();

    Livewire::actingAs($user)
        ->test(Pos::class)
        ->call('addByProductCode', (string) $product->import_code)
        ->call('selectClient', $client->id)
        ->set('customerDiscountType', 'percent')
        ->set('customerDiscountValue', 100)
        ->assertSet('totals.amount', 0.0)
        ->call('selectPaymentType', 'cash')
        ->call('checkout');

    $sale = Sale::query()->latest('id')->firstOrFail();

    expect($sale->customer_discount_amount)->toBe(100_000.0)
        ->and($sale->total_amount)->toBe(0.0)
        ->and($sale->paid_amount)->toBe(0.0)
        ->and($sale->remaining_amount)->toBe(0.0);
});

it('clears a customer discount when another customer is selected', function () {
    [$product, $user] = createVisiblePosProduct($this, [
        'price' => 100000,
    ]);
    $firstClient  = Client::factory()->create();
    $secondClient = Client::factory()->create();

    Livewire::actingAs($user)
        ->test(Pos::class)
        ->call('addByProductCode', (string) $product->import_code)
        ->call('selectClient', $firstClient->id)
        ->set('customerDiscountType', 'fixed')
        ->set('customerDiscountValue', 25000)
        ->assertSet('totals.amount', 75000.0)
        ->call('selectClient', $secondClient->id)
        ->assertSet('customerDiscountValue', null)
        ->assertSet('totals.customer_discount_amount', 0.0)
        ->assertSet('totals.amount', 100000.0);
});

it('adds a debt sale to the existing debtor without creating a duplicate', function () {
    [$product, $user] = createVisiblePosProduct($this, [
        'name'  => 'Qarzga sotiladigan tovar',
        'price' => 125000,
    ]);
    $client = Client::factory()->create();
    $debtor = Debtor::create([
        'store_id'  => $user->current_store_id,
        'client_id' => $client->id,
        'amount'    => 50000,
        'currency'  => 'uzs',
        'date'      => now(),
    ]);

    Livewire::actingAs($user)
        ->test(Pos::class)
        ->call('addByProductCode', (string) $product->import_code)
        ->call('selectClient', $client->id)
        ->call('selectPaymentType', 'debt')
        ->call('checkout');

    $sale = Sale::query()->latest('id')->firstOrFail();

    expect(Debtor::query()
        ->where('store_id', $user->current_store_id)
        ->where('client_id', $client->id)
        ->count())->toBe(1)
        ->and($debtor->refresh()->amount)->toBe(175000)
        ->and(DebtorTransaction::query()->where('sale_id', $sale->id)->value('debtor_id'))->toBe($debtor->id);
});
