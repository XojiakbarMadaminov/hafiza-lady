<?php

use App\Models\User;
use App\Models\Stock;
use App\Models\Store;
use Livewire\Livewire;
use App\Models\Product;
use App\Models\ProductStock;
use Filament\Facades\Filament;
use Filament\Actions\Testing\TestAction;
use Spatie\Permission\Models\Permission;
use App\Services\ProductStockEntryService;
use Spatie\Permission\PermissionRegistrar;
use Illuminate\Validation\ValidationException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Filament\Resources\Products\Pages\ListProducts;
use Illuminate\Database\Eloquent\ModelNotFoundException;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['app.key' => 'base64:' . base64_encode(str_repeat('a', 32))]);
    $this->store = Store::query()->create(['name' => 'Kirim store', 'address' => 'Tashkent', 'phone' => '+998901234567']);
    $this->stock = Stock::query()->create(['name' => 'Asosiy ombor', 'is_main' => true, 'is_active' => true]);
    $this->store->stocks()->attach($this->stock);
    $this->user = User::factory()->create(['current_store_id' => $this->store->id]);
    $this->user->stores()->attach($this->store);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    foreach (['ViewAny:Product', 'Update:Product'] as $permission) {
        $this->user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }
    $this->actingAs($this->user);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    cache()->forget('active_stocks_for_store_' . $this->user->id);
    $this->product = Product::factory()->create([
        'store_id'      => $this->store->id,
        'type'          => Product::TYPE_PACKAGE,
        'initial_price' => 5000,
        'price'         => 10000,
    ]);
    $this->inventory = $this->product->productStocks()->create(['stock_id' => $this->stock->id, 'quantity' => 10]);
    $this->service   = app(ProductStockEntryService::class);
});

it('adds received package quantity without changing product details', function () {
    $original = $this->product->refresh()->getAttributes();

    Livewire::test(ListProducts::class)
        ->callAction(TestAction::make('receiveStock')->table($this->product), [
            'stock_id'      => $this->stock->id,
            'quantity'      => 7,
            'name'          => 'Tampered name',
            'barcode'       => '0000000000000',
            'initial_price' => 1,
            'price'         => 1,
        ])
        ->assertHasNoFormErrors()
        ->assertNotified('Kirim saqlandi');

    expect($this->inventory->refresh()->quantity)->toBe(17)
        ->and($this->product->refresh()->getAttributes())->toBe($original);

    $this->service->receive($this->product, ['stock_id' => $this->stock->id, 'quantity' => 3]);
    expect($this->inventory->refresh()->quantity)->toBe(20);
});

it('shows disabled identity fields and places Kirim before Edit', function () {
    $component = Livewire::test(ListProducts::class);
    $names     = array_keys($component->instance()->getTable()->getFlatActions());
    expect(array_search('receiveStock', $names))->toBeLessThan(array_search('edit', $names));

    $component->mountAction(TestAction::make('receiveStock')->table($this->product))
        ->assertSchemaStateSet(['name' => $this->product->name, 'barcode' => $this->product->barcode])
        ->assertFormFieldIsDisabled('name')
        ->assertFormFieldIsDisabled('barcode')
        ->assertFormFieldIsHidden('stock_id')
        ->assertFormFieldIsVisible('quantity')
        ->assertFormFieldIsHidden('variants');
});

it('creates stock only in the selected warehouse', function () {
    $stock = Stock::query()->create(['name' => 'Ikkinchi ombor', 'is_active' => true]);
    $this->store->stocks()->attach($stock);

    Livewire::test(ListProducts::class)
        ->mountAction(TestAction::make('receiveStock')->table($this->product))
        ->assertFormFieldIsVisible('stock_id')
        ->fillForm(['stock_id' => $stock->id, 'quantity' => 4])
        ->callMountedAction()
        ->assertHasNoFormErrors();

    expect($this->inventory->refresh()->quantity)->toBe(10)
        ->and($this->product->productStocks()->where('stock_id', $stock->id)->value('quantity'))->toBe(4);
});

it('receives existing and new variants while ignoring unselected variants', function (string $type, string $existingName, string $newName) {
    $this->product->update(['type' => $type]);
    $variant   = $this->product->sizes()->create(['size' => $existingName]);
    $inventory = $variant->productStocks()->create(['stock_id' => $this->stock->id, 'quantity' => 8]);

    Livewire::test(ListProducts::class)
        ->mountAction(TestAction::make('receiveStock')->table($this->product))
        ->assertFormFieldIsHidden('quantity')
        ->assertFormFieldIsVisible('variants')
        ->fillForm([
            'stock_id' => $this->stock->id,
            'variants' => [
                ['name' => $existingName, 'selected' => true, 'quantity' => 3],
                ['name' => $newName, 'selected' => true, 'quantity' => 6],
                ['name' => 'Not selected', 'selected' => false, 'quantity' => -5],
            ],
        ])
        ->callMountedAction()
        ->assertHasNoFormErrors()
        ->assertNotified('Kirim saqlandi');

    expect($inventory->refresh()->quantity)->toBe(11)
        ->and($this->product->sizes()->count())->toBe(2)
        ->and($this->product->sizes()->where('size', 'Not selected')->exists())->toBeFalse();
    $newVariant = $this->product->sizes()->where('size', $newName)->firstOrFail();
    expect($newVariant->productStocks()->where('stock_id', $this->stock->id)->value('quantity'))->toBe(6)
        ->and($newVariant->productStocks()->first()->product_id)->toBeNull()
        ->and($this->inventory->refresh()->quantity)->toBe(10);
})->with([
    'sizes'  => [Product::TYPE_SIZE, '37', '42'],
    'colors' => [Product::TYPE_COLOR, 'Qora', 'Jigarrang'],
]);

it('provides six suggestions and keeps all existing variant names', function (string $type) {
    $this->product->update(['type' => $type]);
    $this->product->sizes()->create(['size' => 'Custom']);
    $options = $this->service->variantOptions($this->product);
    expect($options)->toHaveCount(6)
        ->and($options[0])->toBe(['name' => 'Custom', 'selected' => false, 'quantity' => null]);
})->with([Product::TYPE_SIZE, Product::TYPE_COLOR]);

it('renders the variant picker with selectable options and a new variant control', function () {
    $this->product->update(['type' => Product::TYPE_SIZE]);
    $component = Livewire::test(ListProducts::class)
        ->mountAction(TestAction::make('receiveStock')->table($this->product));
    $page   = $component->instance();
    $schema = $page->getSchema($page->getMountedActionSchemaName());
    view()->share('errors', new Illuminate\Support\ViewErrorBag);
    $html = $schema->getComponent('variants')->toHtml();
    expect($html)->toContain('data-testid="stock-entry-variants"', 'data-variant-quantity', 'addVariant()', 'Yangi Razmer qo‘shish');
});

it('rejects invalid package quantities without changing stock', function (mixed $quantity) {
    Livewire::test(ListProducts::class)
        ->callAction(TestAction::make('receiveStock')->table($this->product), ['stock_id' => $this->stock->id, 'quantity' => $quantity])
        ->assertHasFormErrors(['quantity']);

    expect($this->inventory->refresh()->quantity)->toBe(10);
})->with([0, -2, 1.5, 'invalid', null]);

it('rejects invalid variant submissions before saving any variants or quantities', function (array $variants) {
    $this->product->update(['type' => Product::TYPE_SIZE]);
    expect(fn () => $this->service->receive($this->product, ['stock_id' => $this->stock->id, 'variants' => $variants]))
        ->toThrow(ValidationException::class);
    expect($this->product->sizes()->count())->toBe(0)
        ->and(ProductStock::query()->count())->toBe(1);
})->with([
    'nothing selected'          => [[['name' => '37', 'selected' => false, 'quantity' => null]]],
    'missing quantity'          => [[['name' => '37', 'selected' => true, 'quantity' => null]]],
    'negative quantity'         => [[['name' => '37', 'selected' => true, 'quantity' => -1]]],
    'decimal quantity'          => [[['name' => '37', 'selected' => true, 'quantity' => 1.5]]],
    'empty name'                => [[['name' => ' ', 'selected' => true, 'quantity' => 1]]],
    'duplicates'                => [[['name' => 'XL', 'selected' => true, 'quantity' => 1], ['name' => ' xl ', 'selected' => true, 'quantity' => 2]]],
    'valid followed by invalid' => [[['name' => '37', 'selected' => true, 'quantity' => 4], ['name' => '38', 'selected' => true, 'quantity' => 0]]],
]);

it('reuses an existing variant regardless of casing or surrounding whitespace', function () {
    $this->product->update(['type' => Product::TYPE_COLOR]);
    $variant = $this->product->sizes()->create(['size' => 'Qora']);
    $this->service->receive($this->product, ['stock_id' => $this->stock->id, 'variants' => [['name' => ' qora ', 'selected' => true, 'quantity' => 2]]]);
    expect($this->product->sizes()->count())->toBe(1)
        ->and($variant->productStocks()->value('quantity'))->toBe(2);
});

it('rejects inactive or other store warehouses', function (bool $otherStore) {
    $stock = Stock::query()->create(['name' => 'Unavailable stock', 'is_active' => $otherStore]);
    if (!$otherStore) {
        $this->store->stocks()->attach($stock);
    }
    expect(fn () => $this->service->receive($this->product, ['stock_id' => $stock->id, 'quantity' => 5]))
        ->toThrow(ValidationException::class);
    expect($this->inventory->refresh()->quantity)->toBe(10);
})->with([true, false]);

it('rejects products outside the current store', function () {
    $store   = Store::query()->create(['name' => 'Other store', 'address' => 'Other', 'phone' => '+998901234568']);
    $product = Product::factory()->create(['store_id' => $store->id]);
    expect(fn () => $this->service->receive($product, ['stock_id' => $this->stock->id, 'quantity' => 5]))
        ->toThrow(ModelNotFoundException::class);
    expect(ProductStock::query()->where('product_id', $product->id)->exists())->toBeFalse();
});

it('requires product update permission for the action and service', function () {
    $this->user->revokePermissionTo('Update:Product');
    Livewire::test(ListProducts::class)->assertActionHidden(TestAction::make('receiveStock')->table($this->product));
    expect(fn () => $this->service->receive($this->product, ['stock_id' => $this->stock->id, 'quantity' => 5]))
        ->toThrow(AuthorizationException::class);
    expect($this->inventory->refresh()->quantity)->toBe(10);
});

it('does not persist variant suggestions when the modal is cancelled', function () {
    $this->product->update(['type' => Product::TYPE_SIZE]);
    Livewire::test(ListProducts::class)
        ->mountAction(TestAction::make('receiveStock')->table($this->product))
        ->fillForm(['variants' => [['name' => '42', 'selected' => true, 'quantity' => 5]]])
        ->unmountAction();
    expect($this->product->sizes()->count())->toBe(0)
        ->and($this->inventory->refresh()->quantity)->toBe(10);
});
