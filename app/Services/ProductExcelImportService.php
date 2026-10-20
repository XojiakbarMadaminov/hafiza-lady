<?php

namespace App\Services;

use App\Models\User;
use App\Models\Stock;
use App\Models\Store;
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

    public function nextImportCode(User $user): int
    {
        abort_unless($user->hasRole('super_admin'), 403);
        abort_unless($user->current_store_id, 403);

        return (int) Product::withoutGlobalScope('current_store')->withTrashed()
            ->where('store_id', $user->current_store_id)->max('import_code') + 1;
    }

    public function writeExample(string $path, User $user): void
    {
        abort_unless($user->hasRole('super_admin'), 403);

        $firstCode = $this->nextImportCode($user);
        $writer    = new Writer;
        $writer->openToFile($path);

        try {
            $writer->addRow(Row::fromValues(self::HEADERS));

            for ($offset = 0; $offset < 1000; $offset++) {
                $writer->addRow(Row::fromValues([$firstCode + $offset, '', '', '', '']));
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
                Store::query()->whereKey($user->current_store_id)->lockForUpdate()->firstOrFail();
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
                            'id'            => ['required', 'integer', 'min:1', 'max:' . (PHP_INT_MAX - 1000)],
                            'name'          => ['required', 'string', 'max:255'],
                            'initial_price' => ['required', 'integer', 'min:0', 'max:' . PHP_INT_MAX],
                            'price'         => ['required', 'integer', 'min:0', 'max:' . PHP_INT_MAX],
                            'quantity'      => ['required', 'integer', 'min:0', 'max:' . PHP_INT_MAX],
                        ]);

                        if ($validator->fails()) {
                            throw ValidationException::withMessages(['file' => "{$rowNumber}-qator: " . $validator->errors()->first()]);
                        }

                        $product = Product::withoutGlobalScope('current_store')->withTrashed()
                            ->where('store_id', $user->current_store_id)
                            ->where('import_code', $data['id'])
                            ->lockForUpdate()->first();

                        if ($product?->trashed()) {
                            throw ValidationException::withMessages(['file' => "{$rowNumber}-qator: bu import kodidagi mahsulot o‘chirilgan. Uni tiklang yoki yangi koddan foydalaning."]);
                        }

                        if ($product && !$product->isPackageBased()) {
                            throw ValidationException::withMessages(['file' => "{$rowNumber}-qator: razmerli yoki rangli mahsulot sonini bu formatda yangilab bo‘lmaydi."]);
                        }

                        $isNew = !$product;
                        $product ??= new Product(['import_code' => $data['id'], 'store_id' => $user->current_store_id, 'type' => Product::TYPE_PACKAGE]);
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
