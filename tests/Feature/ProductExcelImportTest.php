<?php

use App\Models\User;
use App\Models\Stock;
use App\Models\Store;
use Livewire\Livewire;
use App\Models\Product;
use App\Models\ProductStock;
use Filament\Facades\Filament;
use OpenSpout\Common\Entity\Row;
use Illuminate\Http\UploadedFile;
use OpenSpout\Reader\XLSX\Reader;
use OpenSpout\Writer\XLSX\Writer;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use App\Services\ProductExcelImportService;
use Illuminate\Validation\ValidationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Filament\Resources\Products\Pages\ListProducts;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('app.key', 'base64:' . base64_encode(random_bytes(32)));
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $this->store = Store::query()->create(['name' => 'Import store', 'address' => fake()->address(), 'phone' => fake()->phoneNumber()]);
    $this->stock = Stock::query()->create(['name' => 'Import stock', 'is_active' => true]);
    $this->store->stocks()->attach($this->stock);
    $this->user = User::factory()->create(['current_store_id' => $this->store->id]);
    $this->user->stores()->attach($this->store);
    $this->user->assignRole(Role::findOrCreate('super_admin', 'web'));
    $this->user->givePermissionTo(Permission::findOrCreate('ViewAny:Product', 'web'));
    $this->actingAs($this->user);
    $this->service    = app(ProductExcelImportService::class);
    $this->excelPaths = [];

    $this->makeExcel = function (array $rows, ?array $headers = null): string {
        $path               = tempnam(sys_get_temp_dir(), 'product-import-test-');
        $this->excelPaths[] = $path;
        $writer             = new Writer;
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues($headers ?? ProductExcelImportService::HEADERS));

        foreach ($rows as $values) {
            $writer->addRow(Row::fromValues($values));
        }

        $writer->close();

        return $path;
    };
});

afterEach(function () {
    foreach ($this->excelPaths as $path) {
        if (is_file($path)) {
            unlink($path);
        }
    }
});

it('downloads an excel example containing exactly ids one through one thousand', function () {
    $path = ($this->makeExcel)([]);
    $this->service->writeExample($path, $this->user);
    $reader = new Reader;
    $reader->open($path);
    $rows = [];

    try {
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = $row->toArray();
            }
        }
    } finally {
        $reader->close();
    }

    expect($rows)->toHaveCount(1001)
        ->and($rows[0])->toBe(ProductExcelImportService::HEADERS)
        ->and(array_column(array_slice($rows, 1), 0))->toBe(range(1, 1000));

    Livewire::test(ListProducts::class)
        ->callAction('downloadImportExample')
        ->assertFileDownloaded('products-import-example.xlsx');
});

it('creates and updates products by id and replaces the selected stock quantity', function () {
    $product = Product::factory()->create(['store_id' => $this->store->id, 'barcode' => 'KEEP-BARCODE']);
    $product->productStocks()->create(['stock_id' => $this->stock->id, 'quantity' => 5]);
    $path = ($this->makeExcel)([
        [$product->id, 'Updated product', 10000, 15000, 12],
        [1000, 'New product', 20000, 30000, 8],
    ]);

    expect($this->service->import($path, $this->user, $this->stock->id))
        ->toBe(['created' => 1, 'updated' => 1, 'skipped' => 0]);

    expect($product->refresh()->name)->toBe('Updated product')
        ->and($product->barcode)->toBe('KEEP-BARCODE')
        ->and($product->initial_price)->toBe(10000)
        ->and($product->price)->toBe(15000)
        ->and($product->productStocks()->sole()->quantity)->toBe(12)
        ->and(Product::findOrFail(1000)->store_id)->toBe($this->store->id)
        ->and(Product::findOrFail(1000)->type)->toBe(Product::TYPE_PACKAGE);

    expect($this->service->import($path, $this->user, $this->stock->id))
        ->toBe(['created' => 0, 'updated' => 2, 'skipped' => 0])
        ->and(ProductStock::query()->count())->toBe(2);
});

it('generates unique valid ean thirteen barcodes only when missing and preserves them on reimport', function (?string $missingBarcode) {
    $withoutBarcode = Product::factory()->create(['store_id' => $this->store->id, 'barcode' => $missingBarcode]);
    $withBarcode    = Product::factory()->create(['store_id' => $this->store->id, 'barcode' => '1234567890128']);
    $skipped        = Product::factory()->create(['store_id' => $this->store->id, 'barcode' => 'KEEP-SKIPPED']);
    $path           = ($this->makeExcel)([
        [$withoutBarcode->id, 'Previously without barcode', 10, 20, 1],
        [$withBarcode->id, 'Previously with barcode', 10, 20, 1],
        [$skipped->id, '', '', '', ''],
        [1000, 'New product', 10, 20, 1],
    ]);
    $this->service->import($path, $this->user, $this->stock->id);
    $barcodes = Product::query()->whereKey([$withoutBarcode->id, $withBarcode->id, 1000])->pluck('barcode')->all();

    expect(array_unique($barcodes))->toHaveCount(3)
        ->and($withBarcode->fresh()->barcode)->toBe('1234567890128')
        ->and($skipped->fresh()->barcode)->toBe('KEEP-SKIPPED');

    foreach ($barcodes as $barcode) {
        expect($barcode)->toMatch('/^\d{13}$/');
        $weightedSum = 0;

        foreach (str_split($barcode) as $position => $digit) {
            $weightedSum += (int) $digit * ($position % 2 === 0 ? 1 : 3);
        }

        expect($weightedSum % 10)->toBe(0);
    }

    $this->service->import($path, $this->user, $this->stock->id);
    $newBarcodes = Product::query()->whereKey([$withoutBarcode->id, $withBarcode->id, 1000])->pluck('barcode')->all();

    expect($newBarcodes)->toBe($barcodes)
        ->and(array_unique($newBarcodes))->toHaveCount(3);
})->with([null, '', '   ']);

it('skips rows missing a name or either price and accepts zero values', function () {
    $path = ($this->makeExcel)([
        [1, '', '', '', ''],
        [2, '', 10, 20, 3],
        [3, 'Missing purchase price', '', 20, 3],
        [4, 'Missing sale price', 10, '', 3],
        [5, 'Zero values', 0, 0, 0],
        [6, 'Empty quantity', 10, 20, ''],
    ]);

    expect($this->service->import($path, $this->user, $this->stock->id))
        ->toBe(['created' => 2, 'updated' => 0, 'skipped' => 4])
        ->and(Product::query()->count())->toBe(2)
        ->and(ProductStock::query()->sum('quantity'))->toBe(0);
});

it('imports an uploaded xlsx through the products list action', function () {
    $path   = ($this->makeExcel)([[1000, 'Uploaded product', 100, 200, 4]]);
    $upload = UploadedFile::fake()->createWithContent('products.xlsx', file_get_contents($path));

    Livewire::test(ListProducts::class)
        ->callAction('importProducts', ['stock_id' => $this->stock->id, 'file' => $upload])
        ->assertHasNoActionErrors()
        ->assertNotified('Import yakunlandi');

    expect(Product::findOrFail(1000)->name)->toBe('Uploaded product');
});

it('denies both actions and service access to all non super admin roles', function (string $role) {
    $this->user->syncRoles(Role::findOrCreate($role, 'web'));
    $this->user->givePermissionTo(Permission::findOrCreate('ViewAny:Product', 'web'));

    Livewire::test(ListProducts::class)
        ->assertActionHidden('importProducts')
        ->assertActionHidden('downloadImportExample')
        ->call('mountAction', 'importProducts')
        ->call('callMountedAction')
        ->call('mountAction', 'downloadImportExample')
        ->call('callMountedAction')
        ->assertNoFileDownloaded();

    $path = ($this->makeExcel)([[1000, 'Forbidden', 10, 20, 1]]);

    expect(fn () => $this->service->import($path, $this->user, $this->stock->id))
        ->toThrow(Symfony\Component\HttpKernel\Exception\HttpException::class)
        ->and(fn () => $this->service->writeExample($path, $this->user))
        ->toThrow(Symfony\Component\HttpKernel\Exception\HttpException::class)
        ->and(Product::query()->count())->toBe(0);
})->with(['admin', 'cashier']);

it('shows import errors on the upload field', function () {
    $path   = ($this->makeExcel)([[1000, 'Invalid price', -10, 20, 1]]);
    $upload = UploadedFile::fake()->createWithContent('products.xlsx', file_get_contents($path));

    Livewire::test(ListProducts::class)
        ->callAction('importProducts', ['stock_id' => $this->stock->id, 'file' => $upload])
        ->assertHasActionErrors(['file']);

    expect(Product::query()->count())->toBe(0);
});

it('checks the role again when a mounted import is submitted', function () {
    $path      = ($this->makeExcel)([[1000, 'Forbidden product', 10, 20, 1]]);
    $upload    = UploadedFile::fake()->createWithContent('products.xlsx', file_get_contents($path));
    $component = Livewire::test(ListProducts::class)
        ->mountAction('importProducts')
        ->fillForm(['stock_id' => $this->stock->id, 'file' => $upload]);

    $this->user->syncRoles(Role::findOrCreate('admin', 'web'));
    $this->user->givePermissionTo(Permission::findOrCreate('ViewAny:Product', 'web'));

    $component->call('callMountedAction');

    expect(Product::query()->count())->toBe(0);
});

it('rejects invalid numbers and rolls back the entire import', function (array $invalidRow) {
    $path = ($this->makeExcel)([[999, 'Valid product', 10, 20, 1], $invalidRow]);

    expect(fn () => $this->service->import($path, $this->user, $this->stock->id))
        ->toThrow(ValidationException::class)
        ->and(Product::query()->count())->toBe(0)
        ->and(ProductStock::query()->count())->toBe(0);
})->with([
    'invalid id'          => [[0, 'Product', 10, 20, 1]],
    'invalid price'       => [[1000, 'Product', 'invalid', 20, 1]],
    'negative price'      => [[1000, 'Product', 10, -20, 1]],
    'fractional quantity' => [[1000, 'Product', 10, 20, 1.5]],
]);

it('rejects missing or incorrect column headers', function () {
    $path = ($this->makeExcel)([], ['id', 'name']);

    expect(fn () => $this->service->import($path, $this->user, $this->stock->id))->toThrow(ValidationException::class);
});

it('rejects a corrupt xlsx file without creating products', function () {
    $path               = tempnam(sys_get_temp_dir(), 'invalid-excel-');
    $this->excelPaths[] = $path;

    expect(fn () => $this->service->import($path, $this->user, $this->stock->id))
        ->toThrow(ValidationException::class)
        ->and(Product::query()->count())->toBe(0);
});

it('does not update products from another store or deleted products', function (bool $deleted) {
    $otherStore = Store::query()->create(['name' => 'Other store', 'address' => fake()->address(), 'phone' => fake()->phoneNumber()]);
    $product    = Product::factory()->create(['store_id' => $deleted ? $this->store->id : $otherStore->id]);

    if ($deleted) {
        $product->delete();
    }

    $path = ($this->makeExcel)([[$product->id, 'Do not update', 10, 20, 1]]);

    expect(fn () => $this->service->import($path, $this->user, $this->stock->id))
        ->toThrow(ValidationException::class)
        ->and($product->fresh()->name)->toBe($product->name);
})->with([false, true]);

it('rejects inactive or unrelated stocks', function (bool $inactive) {
    $stock = Stock::query()->create(['name' => 'Invalid stock', 'is_active' => !$inactive]);

    if ($inactive) {
        $this->store->stocks()->attach($stock);
    }

    $path = ($this->makeExcel)([[1000, 'Product', 10, 20, 1]]);

    expect(fn () => $this->service->import($path, $this->user, $stock->id))
        ->toThrow(ValidationException::class)
        ->and(Product::query()->count())->toBe(0);
})->with([false, true]);

it('does not silently convert size or color products to package products', function (string $type) {
    $product = Product::factory()->create(['store_id' => $this->store->id, 'type' => $type]);
    $path    = ($this->makeExcel)([[$product->id, 'Changed name', 10, 20, 1]]);

    expect(fn () => $this->service->import($path, $this->user, $this->stock->id))
        ->toThrow(ValidationException::class)
        ->and($product->fresh()->type)->toBe($type);
})->with([Product::TYPE_SIZE, Product::TYPE_COLOR]);
