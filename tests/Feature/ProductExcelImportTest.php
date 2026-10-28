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

it('downloads an excel example containing codes one through one thousand as text', function () {
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
        ->and(array_column(array_slice($rows, 1), 0))->toBe(array_map('strval', range(1, 1000)));

    Livewire::test(ListProducts::class)
        ->callAction('downloadImportExample')
        ->assertFileDownloaded('products-import-example.xlsx');
});

it('creates and updates products by import code and replaces the selected stock quantity', function () {
    $product = Product::factory()->create(['store_id' => $this->store->id, 'import_code' => 25, 'barcode' => 'KEEP-BARCODE']);
    $product->productStocks()->create(['stock_id' => $this->stock->id, 'quantity' => 5]);
    $path = ($this->makeExcel)([
        [25, 'Updated product', 10000, 15000, 12],
        [1000, 'New product', 20000, 30000, 8],
    ]);

    expect($this->service->import($path, $this->user, $this->stock->id))
        ->toBe(['created' => 1, 'updated' => 1, 'skipped' => 0]);

    expect($product->refresh()->name)->toBe('Updated product')
        ->and($product->barcode)->toBe('KEEP-BARCODE')
        ->and($product->initial_price)->toBe(10000)
        ->and($product->price)->toBe(15000)
        ->and($product->productStocks()->sole()->quantity)->toBe(12)
        ->and(Product::where('import_code', 1000)->sole()->store_id)->toBe($this->store->id)
        ->and(Product::where('import_code', 1000)->sole()->type)->toBe(Product::TYPE_PACKAGE)
        ->and(Product::where('import_code', 1000)->sole()->id)->not->toBe(1000);

    expect($this->service->import($path, $this->user, $this->stock->id))
        ->toBe(['created' => 0, 'updated' => 2, 'skipped' => 0])
        ->and(ProductStock::query()->count())->toBe(2);
});

it('accepts legacy id headers as product codes without matching database ids', function () {
    $product = Product::factory()->create(['store_id' => $this->store->id, 'import_code' => 701]);
    $path    = ($this->makeExcel)([[701, 'Legacy header product', 10, 20, 3]], ProductExcelImportService::LEGACY_HEADERS);

    expect($this->service->import($path, $this->user, $this->stock->id))
        ->toBe(['created' => 0, 'updated' => 1, 'skipped' => 0])
        ->and($product->fresh()->name)->toBe('Legacy header product');
});

it('updates manually created products by their automatically assigned codes', function () {
    Product::factory()->create(['store_id' => $this->store->id, 'import_code' => 700]);
    $manual = Product::factory()->create(['store_id' => $this->store->id]);
    $path   = ($this->makeExcel)([[$manual->import_code, 'Updated manual product', 10, 20, 3]]);

    expect($manual->import_code)->toBe(701)
        ->and($this->service->import($path, $this->user, $this->stock->id))
        ->toBe(['created' => 0, 'updated' => 1, 'skipped' => 0])
        ->and($manual->fresh()->name)->toBe('Updated manual product')
        ->and($manual->fresh()->import_code)->toBe(701)
        ->and($this->service->nextImportCode($this->user))->toBe(702);
});

it('stores numeric excel product names as strings on creation and update', function (int|float|string $name, string $expectedName) {
    $product = Product::factory()->create(['store_id' => $this->store->id, 'import_code' => 25]);
    $path    = ($this->makeExcel)([
        [25, $name, 60, 100, 10],
        [1000, $name, 60, 100, 10],
    ]);

    expect($this->service->import($path, $this->user, $this->stock->id))
        ->toBe(['created' => 1, 'updated' => 1, 'skipped' => 0])
        ->and($product->refresh()->name)->toBe($expectedName)
        ->and(Product::where('import_code', 1000)->sole()->name)->toBe($expectedName);
})->with([
    'integer'                           => [1, '1'],
    'zero'                              => [0, '0'],
    'negative integer'                  => [-12, '-12'],
    'decimal'                           => [12.5, '12.5'],
    'numeric string with leading zeros' => ['00123', '00123'],
    'trimmed string'                    => ['  Product  ', 'Product'],
]);

it('generates unique valid ean thirteen barcodes only when missing and preserves them on reimport', function (?string $missingBarcode) {
    $withoutBarcode = Product::factory()->create(['store_id' => $this->store->id, 'import_code' => 25, 'barcode' => $missingBarcode]);
    $withBarcode    = Product::factory()->create(['store_id' => $this->store->id, 'import_code' => 26, 'barcode' => '1234567890128']);
    $skipped        = Product::factory()->create(['store_id' => $this->store->id, 'barcode' => 'KEEP-SKIPPED']);
    $path           = ($this->makeExcel)([
        [25, 'Previously without barcode', 10, 20, 1],
        [26, 'Previously with barcode', 10, 20, 1],
        [$skipped->id, '', '', '', ''],
        [1000, 'New product', 10, 20, 1],
    ]);
    $this->service->import($path, $this->user, $this->stock->id);
    $barcodes = Product::query()->whereIn('import_code', [25, 26, 1000])->orderBy('import_code')->pluck('barcode')->all();

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
    $newBarcodes = Product::query()->whereIn('import_code', [25, 26, 1000])->orderBy('import_code')->pluck('barcode')->all();

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

    expect(Product::where('import_code', 1000)->sole()->name)->toBe('Uploaded product');
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

it('accepts header whitespace and trailing empty columns', function (array $headers) {
    $path = ($this->makeExcel)([[21, 1901, 79000, 99000, 20]], $headers);

    expect($this->service->import($path, $this->user, $this->stock->id))
        ->toBe(['created' => 1, 'updated' => 0, 'skipped' => 0])
        ->and(Product::query()->where('import_code', 21)->sole()->name)->toBe('1901');
})->with([
    'extra empty columns'               => [['tovar_kodi', 'nomi', 'kelgan_narx', 'sotuv_narxi', 'soni', '', '', '']],
    'surrounding whitespace'            => [[' tovar_kodi ', "nomi\t", ' kelgan_narx', 'sotuv_narxi ', "soni\n"]],
    'invisible characters'              => [["\xEF\xBB\xBFtovar_kodi", "\xC2\xA0nomi\xC2\xA0", 'kelgan_narx', 'sotuv_narxi', 'soni', "\xC2\xA0"]],
    'legacy headers with empty columns' => [['id', 'nomi', 'kelgan_narx', 'sotuv_narxi', 'soni', '']],
]);

it('rejects missing or incorrect column headers', function (array $headers) {
    $path = ($this->makeExcel)([], $headers);

    expect(fn () => $this->service->import($path, $this->user, $this->stock->id))->toThrow(ValidationException::class);
})->with([
    'missing columns'              => [['id', 'name']],
    'unexpected column'            => [['tovar_kodi', 'nomi', 'kelgan_narx', 'sotuv_narxi', 'soni', 'extra']],
    'wrong column order'           => [['tovar_kodi', 'nomi', 'sotuv_narxi', 'kelgan_narx', 'soni']],
    'empty column between headers' => [['tovar_kodi', 'nomi', '', 'kelgan_narx', 'sotuv_narxi', 'soni']],
]);

it('accepts worksheets whose used range extends beyond the header columns', function () {
    $path    = ($this->makeExcel)([[21, 'Product', 79000, 99000, 20]]);
    $archive = new ZipArchive;
    $archive->open($path);
    $sheetXml = $archive->getFromName('xl/worksheets/sheet1.xml');
    $archive->addFromString('xl/worksheets/sheet1.xml', str_replace('<sheetData>', '<dimension ref="A1:I2"/><sheetData>', $sheetXml));
    $archive->close();

    expect($this->service->import($path, $this->user, $this->stock->id))
        ->toBe(['created' => 1, 'updated' => 0, 'skipped' => 0]);
});

it('rejects duplicate codes without partially changing existing products or the sequence', function () {
    $product = Product::factory()->create(['store_id' => $this->store->id, 'import_code' => 80]);
    $path    = ($this->makeExcel)([
        [80, 'Must be rolled back', 10, 20, 2],
        [900, 'New product', 10, 20, 2],
        [80, 'Duplicate code', 10, 20, 3],
    ]);

    expect(fn () => $this->service->import($path, $this->user, $this->stock->id))
        ->toThrow(ValidationException::class)
        ->and($product->fresh()->name)->toBe($product->name)
        ->and(Product::query()->count())->toBe(1)
        ->and($this->service->nextImportCode($this->user))->toBe(81);
});

it('rejects a corrupt xlsx file without creating products', function () {
    $path               = tempnam(sys_get_temp_dir(), 'invalid-excel-');
    $this->excelPaths[] = $path;

    expect(fn () => $this->service->import($path, $this->user, $this->stock->id))
        ->toThrow(ValidationException::class)
        ->and(Product::query()->count())->toBe(0);
});

it('isolates import codes by store and reserves deleted product codes', function (bool $deleted) {
    $otherStore = Store::query()->create(['name' => 'Other store', 'address' => fake()->address(), 'phone' => fake()->phoneNumber()]);
    $product    = Product::factory()->create(['store_id' => $deleted ? $this->store->id : $otherStore->id, 'import_code' => 25]);

    if ($deleted) {
        $product->delete();
    }

    $path = ($this->makeExcel)([[25, 'Do not update', 10, 20, 1]]);

    if ($deleted) {
        expect(fn () => $this->service->import($path, $this->user, $this->stock->id))
            ->toThrow(ValidationException::class);
    } else {
        expect($this->service->import($path, $this->user, $this->stock->id))
            ->toBe(['created' => 1, 'updated' => 0, 'skipped' => 0]);
    }

    expect(Product::withoutGlobalScope('current_store')->withTrashed()->findOrFail($product->id)->name)->toBe($product->name);
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
    $product = Product::factory()->create(['store_id' => $this->store->id, 'import_code' => 25, 'type' => $type]);
    $path    = ($this->makeExcel)([[25, 'Changed name', 10, 20, 1]]);

    expect(fn () => $this->service->import($path, $this->user, $this->stock->id))
        ->toThrow(ValidationException::class)
        ->and($product->fresh()->type)->toBe($type);
})->with([Product::TYPE_SIZE, Product::TYPE_COLOR]);

it('never matches manually created products by their database ids', function (string $type) {
    $this->service->import(($this->makeExcel)(collect(range(1, 10))
        ->map(fn (int $code): array => [$code + 100, "Imported {$code}", 10, 20, 1])->all()), $this->user, $this->stock->id);
    $manualProducts = Product::factory()->count(5)->create(['store_id' => $this->store->id, 'type' => $type]);
    $manual         = $manualProducts->first();
    $path           = ($this->makeExcel)([[$manual->id, 'New Excel product', 30, 40, 7]]);

    expect($this->service->import($path, $this->user, $this->stock->id))
        ->toBe(['created' => 1, 'updated' => 0, 'skipped' => 0])
        ->and(Product::query()->count())->toBe(16)
        ->and($manual->fresh()->name)->toBe($manual->name)
        ->and($manual->fresh()->barcode)->toBe($manual->barcode)
        ->and($manual->fresh()->import_code)->toBe(111);

    $imported = Product::where('import_code', $manual->id)->sole();
    expect($imported->id)->not->toBe($manual->id)
        ->and($imported->name)->toBe('New Excel product');

    expect($this->service->import($path, $this->user, $this->stock->id))
        ->toBe(['created' => 0, 'updated' => 1, 'skipped' => 0])
        ->and(Product::query()->count())->toBe(16);
})->with([Product::TYPE_PACKAGE, Product::TYPE_SIZE, Product::TYPE_COLOR]);

it('starts the thousand row example after the largest code including deleted products in the current store', function () {
    Product::factory()->create(['store_id' => $this->store->id, 'import_code' => 124]);
    Product::factory()->create(['store_id' => $this->store->id, 'import_code' => 125])->delete();
    Product::factory()->create(['store_id' => $this->store->id, 'id' => 5000]);
    $otherStore = Store::query()->create(['name' => 'Other store', 'address' => fake()->address(), 'phone' => fake()->phoneNumber()]);
    Product::factory()->create(['store_id' => $otherStore->id, 'import_code' => 9000]);

    expect($this->service->nextImportCode($this->user))->toBe(127);

    $path = ($this->makeExcel)([]);
    $this->service->writeExample($path, $this->user);
    $reader = new Reader;
    $reader->open($path);
    $codes = [];

    try {
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $rowNumber => $row) {
                if ($rowNumber > 1) {
                    $codes[] = $row->toArray()[0];
                }
            }
        }
    } finally {
        $reader->close();
    }

    expect($codes)->toBe(array_map('strval', range(127, 1126)))
        ->and($this->service->import($path, $this->user, $this->stock->id))
        ->toBe(['created' => 0, 'updated' => 0, 'skipped' => 1000])
        ->and($this->service->nextImportCode($this->user))->toBe(127);
});

it('shows only product codes while advancing them after a list page import', function () {
    $path   = ($this->makeExcel)([[10, 'New product', 100, 200, 4]]);
    $upload = UploadedFile::fake()->createWithContent('products.xlsx', file_get_contents($path));

    Livewire::test(ListProducts::class)
        ->assertDontSee('Keyingi import kodi:')
        ->assertTableColumnExists('import_code')
        ->assertTableColumnDoesNotExist('id')
        ->callAction('importProducts', ['stock_id' => $this->stock->id, 'file' => $upload])
        ->assertHasNoActionErrors()
        ->assertDontSee('Keyingi import kodi:')
        ->assertTableColumnExists('import_code')
        ->assertTableColumnDoesNotExist('id')
        ->assertCanSeeTableRecords(Product::query()->get())
        ->searchTableColumns(['import_code' => '10'])
        ->assertCanSeeTableRecords(Product::query()->where('import_code', 10)->get());

    expect($this->service->nextImportCode($this->user))->toBe(11);
});

it('never generates example codes beyond the supported integer range', function () {
    $this->store->update(['last_product_code' => PHP_INT_MAX - 1001]);
    $path = ($this->makeExcel)([]);
    $this->service->writeExample($path, $this->user);
    $reader = new Reader;
    $reader->open($path);
    $codes = [];

    try {
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $rowNumber => $row) {
                if ($rowNumber > 1) {
                    $codes[] = $row->toArray()[0];
                }
            }
        }
    } finally {
        $reader->close();
    }

    expect($codes)->toBe([(string) (PHP_INT_MAX - 1000)]);
    $this->store->update(['last_product_code' => PHP_INT_MAX - 1000]);

    expect(fn () => $this->service->nextImportCode($this->user))->toThrow(ValidationException::class);
});
