<?php

namespace App\Services;

use App\Models\Stock;
use App\Models\Product;
use App\Models\ProductStock;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ProductStockEntryService
{
    public function variantOptions(Product $product): array
    {
        $names    = $product->sizes()->pluck('size')->filter()->values()->all();
        $defaults = $product->isColorBased()
            ? ['Qora', 'Oq', 'Qizil', 'Ko‘k', 'Yashil', 'Sariq']
            : ['36', '37', '38', '39', '40', '41'];

        foreach ($defaults as $name) {
            if (count($names) >= 6) {
                break;
            }

            if (!collect($names)->contains(fn (string $existing): bool => mb_strtolower(trim($existing)) === mb_strtolower($name))) {
                $names[] = $name;
            }
        }

        return array_map(fn (string $name): array => [
            'name'     => $name,
            'selected' => false,
            'quantity' => null,
        ], $names);
    }

    public function receive(Product $product, array $data): int
    {
        Gate::authorize('update', $product);

        $validated = Validator::make($data, [
            'stock_id'            => ['required', 'integer'],
            'quantity'            => $product->isPackageBased() ? ['required', 'integer', 'min:1', 'max:2147483647'] : ['nullable'],
            'variants'            => $product->isPackageBased() ? ['nullable'] : ['required', 'array', 'max:200'],
            'variants.*'          => ['array'],
            'variants.*.selected' => ['required', 'boolean'],
            'variants.*.name'     => ['nullable'],
            'variants.*.quantity' => ['nullable'],
        ], [
            'quantity.required' => 'Qo‘shiladigan sonni kiriting.',
            'quantity.integer'  => 'Son butun raqam bo‘lishi kerak.',
            'quantity.min'      => 'Qo‘shiladigan son kamida 1 bo‘lishi kerak.',
            'variants.required' => 'Kamida bitta razmer yoki rangni tanlang.',
        ])->validate();

        $stock = Stock::query()->active()->find($validated['stock_id']);

        if (!auth()->user()?->current_store_id || !$stock) {
            throw ValidationException::withMessages(['stock_id' => 'Joriy do‘konga tegishli faol omborni tanlang.']);
        }

        $variants = [];

        if (!$product->isPackageBased()) {
            foreach ($validated['variants'] as $variant) {
                if (!$variant['selected']) {
                    continue;
                }

                $variant['name'] = is_string($variant['name'] ?? null) ? trim($variant['name']) : null;
                $validation      = Validator::make($variant, [
                    'name'     => ['required', 'string', 'max:255'],
                    'quantity' => ['required', 'integer', 'min:1', 'max:2147483647'],
                ], [
                    'name.required'     => 'Razmer yoki rang nomini kiriting.',
                    'quantity.required' => 'Tanlangan variant uchun son kiriting.',
                    'quantity.integer'  => 'Son butun raqam bo‘lishi kerak.',
                    'quantity.min'      => 'Qo‘shiladigan son kamida 1 bo‘lishi kerak.',
                ]);

                if ($validation->fails()) {
                    throw ValidationException::withMessages(['variants' => $validation->errors()->all()]);
                }

                $key = mb_strtolower($variant['name']);

                if (isset($variants[$key])) {
                    throw ValidationException::withMessages(['variants' => 'Bir xil razmer yoki rangni takror tanlamang.']);
                }

                $variants[$key] = $validation->validated();
            }

            if (!$variants) {
                throw ValidationException::withMessages(['variants' => 'Kamida bitta razmer yoki rangni tanlab, sonini kiriting.']);
            }
        }

        return $product->getConnection()->transaction(function () use ($product, $stock, $validated, $variants): int {
            $product = Product::query()->lockForUpdate()->findOrFail($product->id);

            if ($product->isPackageBased()) {
                $quantity  = (int) $validated['quantity'];
                $inventory = ProductStock::query()->firstOrCreate([
                    'product_id'      => $product->id,
                    'product_size_id' => null,
                    'stock_id'        => $stock->id,
                ], ['quantity' => 0]);
                $inventory->increment('quantity', $quantity);

                return $quantity;
            }

            $existingVariants = $product->sizes()->get();
            $total            = 0;

            foreach ($variants as $key => $variantData) {
                $variant = $existingVariants->first(fn ($variant): bool => mb_strtolower(trim((string) $variant->size)) === (string) $key)
                    ?? $product->sizes()->create(['size' => $variantData['name']]);
                $quantity  = (int) $variantData['quantity'];
                $inventory = ProductStock::query()->firstOrCreate([
                    'product_id'      => null,
                    'product_size_id' => $variant->id,
                    'stock_id'        => $stock->id,
                ], ['quantity' => 0]);
                $inventory->increment('quantity', $quantity);
                $total += $quantity;
            }

            return $total;
        });
    }
}
