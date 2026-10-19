<?php

namespace App\Services;

use App\Models\User;
use App\Models\Stock;
use App\Helpers\Helper;
use App\Models\Product;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Reader\XLSX\Reader;
use OpenSpout\Writer\XLSX\Writer;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use OpenSpout\Common\Exception\OpenSpoutException;

class ProductExcelImportService
{
    public const HEADERS = ['id', 'nomi', 'kelgan_narx', 'sotuv_narxi', 'soni'];

    public function writeExample(string $path, User $user): void
    {
        abort_unless($user->hasRole('super_admin'), 403);

        $writer = new Writer;
        $writer->openToFile($path);

        try {
            $writer->addRow(Row::fromValues(self::HEADERS));

            for ($productId = 1; $productId <= 1000; $productId++) {
                $writer->addRow(Row::fromValues([$productId, '', '', '', '']));
            }
        } finally {
            $writer->close();
        }
    }

    /** @return array{created: int, updated: int, skipped: int} */
    public function import(string $path, User $user, int $stockId): array
    {
        abort_unless($user->hasRole('super_admin'), 403);

        $stock = Stock::query()->active()
            ->whereHas('stores', fn ($query) => $query->whereKey($user->current_store_id))
            ->find($stockId);

        if (!$user->current_store_id || !$stock) {
            throw ValidationException::withMessages(['stock_id' => 'Joriy do‘konga tegishli faol omborni tanlang.']);
        }

        $reader = new Reader;

        try {
            $reader->open($path);

            return (new Product)->getConnection()->transaction(function () use ($reader, $user, $stock): array {
                $counts = ['created' => 0, 'updated' => 0, 'skipped' => 0];

                foreach ($reader->getSheetIterator() as $sheet) {
                    $hasHeader = false;

                    foreach ($sheet->getRowIterator() as $rowNumber => $row) {
                        $values = $row->toArray();

                        if (!$hasHeader) {
                            if ($values !== self::HEADERS) {
                                throw ValidationException::withMessages(['file' => 'Ustunlar namunadagidek bo‘lishi kerak: ' . implode(', ', self::HEADERS)]);
                            }

                            $hasHeader = true;

                            continue;
                        }

                        if (blank($values[1] ?? null) || blank($values[2] ?? null) || blank($values[3] ?? null)) {
                            $counts['skipped']++;

                            continue;
                        }

                        $data = [
                            'id'            => $values[0] ?? null,
                            'name'          => is_scalar($values[1]) ? trim((string) $values[1]) : $values[1],
                            'initial_price' => $values[2],
                            'price'         => $values[3],
                            'quantity'      => blank($values[4] ?? null) ? 0 : $values[4],
                        ];

                        $validator = Validator::make($data, [
                            'id'            => ['required', 'integer', 'min:1', 'max:' . (PHP_INT_MAX - 1)],
                            'name'          => ['required', 'string', 'max:255'],
                            'initial_price' => ['required', 'integer', 'min:0', 'max:' . PHP_INT_MAX],
                            'price'         => ['required', 'integer', 'min:0', 'max:' . PHP_INT_MAX],
                            'quantity'      => ['required', 'integer', 'min:0', 'max:' . PHP_INT_MAX],
                        ]);

                        if ($validator->fails()) {
                            throw ValidationException::withMessages(['file' => "{$rowNumber}-qator: " . $validator->errors()->first()]);
                        }

                        $product = Product::withoutGlobalScope('current_store')->withTrashed()->find($data['id']);

                        if ($product && ($product->trashed() || (int) $product->store_id !== (int) $user->current_store_id)) {
                            throw ValidationException::withMessages(['file' => "{$rowNumber}-qator: ID boshqa do‘konga tegishli yoki mahsulot o‘chirilgan."]);
                        }

                        if ($product && !$product->isPackageBased()) {
                            throw ValidationException::withMessages(['file' => "{$rowNumber}-qator: razmerli yoki rangli mahsulot sonini bu formatda yangilab bo‘lmaydi."]);
                        }

                        $isNew = !$product;
                        $product ??= new Product(['id' => $data['id'], 'store_id' => $user->current_store_id, 'type' => Product::TYPE_PACKAGE]);
                        if (blank($product->barcode)) {
                            $product->barcode = $this->generateUniqueBarcode();
                        }
                        $product->fill(array_intersect_key($data, array_flip(['name', 'initial_price', 'price'])))->save();
                        $product->productStocks()->updateOrCreate(['stock_id' => $stock->id], ['quantity' => $data['quantity']]);
                        $counts[$isNew ? 'created' : 'updated']++;
                    }

                    if (!$hasHeader) {
                        throw ValidationException::withMessages(['file' => 'Excel faylda ustun sarlavhalari mavjud emas.']);
                    }

                    break;
                }

                $connection = (new Product)->getConnection();

                if ($counts['created'] > 0 && $connection->getDriverName() === 'pgsql') {
                    $connection->statement("SELECT setval(pg_get_serial_sequence('products', 'id'), GREATEST((SELECT MAX(id) FROM products), nextval(pg_get_serial_sequence('products', 'id'))), true)");
                }

                return $counts;
            });
        } catch (OpenSpoutException $exception) {
            throw ValidationException::withMessages(['file' => 'Excel faylni o‘qib bo‘lmadi. To‘g‘ri .xlsx fayl yuklang.']);
        } finally {
            $reader->close();
        }
    }

    private function generateUniqueBarcode(): string
    {
        do {
            $barcode = Helper::generateEAN13Barcode();
        } while (Product::withoutGlobalScope('current_store')->withTrashed()->where('barcode', $barcode)->exists());

        return $barcode;
    }
}
