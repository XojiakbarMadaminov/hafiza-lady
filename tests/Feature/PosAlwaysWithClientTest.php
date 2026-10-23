<?php

use App\Models\Sale;
use App\Models\User;
use App\Models\Stock;
use App\Models\Store;
use Livewire\Livewire;
use App\Models\Product;
use App\Filament\Pages\Pos;
use App\Models\ProductStock;
use Filament\Facades\Filament;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('hides clientless sale controls from other roles when client is required', function () {
    [$product, $user] = createAlwaysWithClientPosContext('cashier', true);

    Livewire::actingAs($user)
        ->test(Pos::class)
        ->call('addByProductCode', (string) $product->import_code)
        ->assertDontSeeHtml('data-testid="sale-without-client-controls"');
});

it('shows clientless sale controls to privileged roles when client is required', function (string $role) {
    [$product, $user] = createAlwaysWithClientPosContext($role, true);

    Livewire::actingAs($user)
        ->test(Pos::class)
        ->call('addByProductCode', (string) $product->import_code)
        ->assertSeeHtml('data-testid="sale-without-client-controls"');
})->with(['admin', 'super_admin']);

it('shows clientless sale controls to other roles when client is not required', function () {
    [$product, $user] = createAlwaysWithClientPosContext('cashier', false);

    Livewire::actingAs($user)
        ->test(Pos::class)
        ->call('addByProductCode', (string) $product->import_code)
        ->assertSeeHtml('data-testid="sale-without-client-controls"');
});

it('prevents other roles from bypassing the required client setting', function () {
    [$product, $user] = createAlwaysWithClientPosContext('cashier', true);

    Livewire::actingAs($user)
        ->test(Pos::class)
        ->call('addByProductCode', (string) $product->import_code)
        ->set('saleWithoutClient', true)
        ->set('paymentType', 'cash')
        ->call('checkout')
        ->assertNotified('Klient tanlanmagan');

    expect(Sale::query()->doesntExist())->toBeTrue();
});

/**
 * @return array{0: Product, 1: User}
 */
function createAlwaysWithClientPosContext(string $role, bool $alwaysWithClient): array
{
    $store = Store::query()->create([
        'name'               => fake()->company(),
        'address'            => fake()->address(),
        'phone'              => fake()->phoneNumber(),
        'always_with_client' => $alwaysWithClient,
    ]);
    $stock = Stock::query()->create([
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
    Permission::findOrCreate('View:Pos', 'web');
    Role::findOrCreate($role, 'web');
    $user->givePermissionTo('View:Pos');
    $user->assignRole($role);

    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $product = Product::factory()->create([
        'store_id' => $store->id,
        'type'     => Product::TYPE_PACKAGE,
        'price'    => 100_000,
    ]);
    ProductStock::query()->create([
        'product_id' => $product->id,
        'stock_id'   => $stock->id,
        'quantity'   => 10,
    ]);

    return [$product, $user];
}
