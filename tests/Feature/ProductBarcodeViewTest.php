<?php

use App\Models\Product;
use Barryvdh\DomPDF\Facade\Pdf;

it('renders the product id next to the barcode label name', function () {
    $product          = new Product;
    $product->id      = 263;
    $product->name    = 'BD/BC 203GC Xingx Morozilnik';
    $product->barcode = '6959854200118';
    $product->price   = 150000;

    $html = view('product-barcode', [
        'products'       => collect([$product]),
        'size'           => '57x30',
        'discountPrices' => collect(),
    ])->render();

    expect($html)
        ->toContain('150 000')
        ->toContain('(ID#263)')
        ->not->toContain('ID 263')
        ->not->toContain('Kod 263')
        ->not->toContain('Код 263');
});

it('keeps each thirty by twenty millimetre barcode label on one page', function (string $name, bool $discounted, int $quantity) {
    $products       = collect();
    $discountPrices = collect();

    for ($productId = 1; $productId <= $quantity; $productId++) {
        $product          = new Product;
        $product->id      = $productId;
        $product->name    = $name;
        $product->barcode = '6959854200118';
        $product->price   = 150000;
        $products->push($product);

        if ($discounted) {
            $discountPrices->put($productId, [
                'has_discount'     => true,
                'original_price'   => 150000,
                'discounted_price' => 120000,
            ]);
        }
    }

    $pdf = Pdf::loadView('product-barcode', [
        'products'       => $products,
        'size'           => '30x20',
        'discountPrices' => $discountPrices,
    ])->setPaper([0, 0, 85.04, 56.69]);
    $pdf->render();

    expect($pdf->getDomPDF()->getCanvas()->get_page_count())->toBe($quantity);
})->with([
    'short name'       => ['Sovun', false, 1],
    'long name'        => ['BD/BC 203GC Xingx Morozilnik katta hajmli mahsulot', false, 1],
    'discounted label' => ['BD/BC 203GC Xingx Morozilnik katta hajmli mahsulot', true, 1],
    'bulk labels'      => ['BD/BC 203GC Xingx Morozilnik katta hajmli mahsulot', false, 3],
]);
