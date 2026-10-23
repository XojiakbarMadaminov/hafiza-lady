<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        @page {
            margin: 0;
        }

        @font-face {
            font-family: 'DejaVu Sans';
            src: url("{{ public_path('fonts/DejaVuSans.ttf') }}") format('truetype');
        }

        body {
            margin: 0;
            padding: 0;
            font-family: 'DejaVu Sans', sans-serif;
        }

        /* Har bir label alohida sahifa bo‘lsin */
        .label {
            /*width: 100%;*/
            /*height: 100%;*/
            padding: 1mm;
            box-sizing: border-box;
            text-align: center;

            page-break-inside: avoid;
            page-break-after: always; /* har product yangi sahifa */
        }

        /* Oxirgi sahifada bo‘sh page chiqmasin */
        .label:last-child {
            page-break-after: auto;
        }

        .product-name {
            max-width: 100%;
            margin: 0 auto 1mm auto;
            line-height: 1.1;
            word-wrap: break-word;
            overflow-wrap: break-word;
            white-space: normal;
            text-align: center;
            font-family: 'DejaVu Sans', sans-serif;
        }


        .barcode {
            display: block;
            width: 100%;
            text-align: center;
            margin: 0 auto;
        }

        .label-compact {
            padding: 0.5mm;
            font-size: 0;
            line-height: 0;
        }

        .label-compact .product-name {
            margin: 0 auto;
        }

        .label-compact .label-name {
            height: 22px;
            line-height: 9px;
            overflow: hidden;
        }

        .label-compact .barcode {
            height: 20px;
            line-height: 0;
        }

    </style>
</head>
<body>
{{-- DomPDF ba'zida birinchi bo‘sh sahifa chiqarib yubormasligi uchun hiyla --}}
<div style="display:none">&nbsp;</div>

@php
    $labelSize = $size ?? '30x20';
    $discountPrices = $discountPrices ?? collect();

    // Sizing presets per label
    $presets = [
        '30x20' => [
            'name_font' => function (int $len): string {
                if ($len <= 18) { return '9px'; }
                if ($len <= 30) { return '8px'; }
                return '7px';
            },
            'name_max'   => 40,
            'barcode'    => ['scale' => 1.0, 'height' => 20],
            'code_font'  => '8px',
            'price_font' => function (int $len): string {
                if ($len <= 12) { return '9px'; }
                if ($len <= 16) { return '8px'; }
                return '7px';
            },
        ],
        '57x30' => [
            'name_font' => function (int $len): string {
                if ($len <= 30) { return '12px'; }
                if ($len <= 45) { return '10px'; }
                return '9px';
            },
            'name_max'   => 60,
            'barcode'    => ['scale' => 1.3, 'height' => 34],
            'code_font'  => '10px',
            'price_font' => function (int $len): string {
                if ($len <= 12) { return '12px'; }
                if ($len <= 18) { return '11px'; }
                return '10px';
            },
        ],
        '85x65' => [
            'name_font' => function (int $len): string {
                if ($len <= 40) { return '18px'; }
                if ($len <= 60) { return '16px'; }
                return '14px';
            },
            'name_max'   => 90,
            'barcode'    => ['scale' => 2.0, 'height' => 50],
            'code_font'  => '12px',
            'price_font' => function (int $len): string {
                if ($len <= 14) { return '16px'; }
                if ($len <= 20) { return '14px'; }
                return '12px';
            },
        ],
    ];

    $cfg = $presets[$labelSize] ?? $presets['30x20'];
@endphp

@foreach($products as $product)
    @php
        $productCodeSuffix = $product->import_code !== null ? " (Kod: {$product->import_code})" : '';
        $suffixLen = function_exists('mb_strlen') ? mb_strlen($productCodeSuffix) : strlen($productCodeSuffix);

        $name = (string) ($product->name ?? '');
        $len  = function_exists('mb_strlen') ? mb_strlen($name) : strlen($name);
        $max  = max(1, $cfg['name_max'] - $suffixLen);
        if ($len > $max) {
            $name = (function_exists('mb_substr') ? mb_substr($name, 0, $max) : substr($name, 0, $max)) . '…';
            $len  = function_exists('mb_strlen') ? mb_strlen($name) : strlen($name);
        }

        $name .= $productCodeSuffix;
        $len  = function_exists('mb_strlen') ? mb_strlen($name) : strlen($name);

        $nameFont   = ($cfg['name_font'])($len);
        $scale      = $cfg['barcode']['scale'];
        $barHeight  = $cfg['barcode']['height'];
        $codeFont   = $cfg['code_font'];

        $discountPrice = $discountPrices instanceof \Illuminate\Support\Collection
            ? $discountPrices->get($product->id)
            : ($discountPrices[$product->id] ?? null);
        $hasDiscountPrice = (bool) ($discountPrice['has_discount'] ?? false)
            && (float) ($discountPrice['discounted_price'] ?? 0) < (float) ($discountPrice['original_price'] ?? 0);

        $price      = (int) ($hasDiscountPrice ? $discountPrice['discounted_price'] : ($product->price ?? 0));
        $priceStr   = number_format($price, 0, '.', ' ') . " so'm";
        $oldPriceStr = $hasDiscountPrice
            ? number_format((int) $discountPrice['original_price'], 0, '.', ' ') . " so'm"
            : null;
        $pLen       = function_exists('mb_strlen') ? mb_strlen($priceStr) : strlen($priceStr);
        $priceFont  = is_callable($cfg['price_font']) ? ($cfg['price_font'])($pLen) : ($cfg['price_font'] ?? $codeFont);
        $priceFontPx = (float) str_replace('px', '', $priceFont);
        $oldPriceFont = round($priceFontPx * 0.45, 1) . 'px';
        $discountPriceFont = round($priceFontPx * 0.55, 1) . 'px';
    @endphp

    <div @class(['label', 'label-compact' => $labelSize === '30x20'])>
        <div class="product-name label-name" style="font-size: {{ $nameFont }}">{{ $name }}</div>

        <div class="barcode">
            <div style="display:inline-block;">
                {!! DNS1D::getBarcodeHTML($product->barcode, 'EAN13', $scale, $barHeight) !!}
            </div>
        </div>

        <div class="product-name" style="font-size: {{ $codeFont }}">{{ $product->barcode }}</div>

        @if($hasDiscountPrice)
            <div style="margin-top: 0.5mm;">
                <div class="product-name" style="font-size: {{ $oldPriceFont }}; line-height: 1; margin: 0 auto; text-decoration: line-through;">
                    {{ $oldPriceStr }}
                </div>
                <div class="product-name" style="font-size: {{ $discountPriceFont }}; line-height: 1; margin: 0 auto; font-weight: 700;">
                    {{ $priceStr }}
                </div>
            </div>
        @else
            <div class="product-name" style="font-size: {{ $priceFont }}; font-weight: 700; margin-top: {{ $labelSize === '30x20' ? '0' : '1mm' }};">
                {{ $priceStr }}
            </div>
        @endif
    </div>
@endforeach
</body>
</html>
