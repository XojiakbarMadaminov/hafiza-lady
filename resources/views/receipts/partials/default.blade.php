@php
    $data = $receiptData ?? [];
    $meta = $data['meta'] ?? [];
    $paymentType = $meta['payment_type'] ?? null;
    $paymentLabel = match ($paymentType) {
        'cash'     => 'Naqd',
        'card'     => 'Karta',
        'debt'     => 'Qarz',
        'transfer' => 'O\'tkazma',
        'partial'  => 'Qisman',
        'mixed'    => 'Karta + Naqd',
        'preorder' => 'Oldindan buyurtma',
        default    => 'Noma\'lum',
    };

    $items      = $data['items'] ?? [];
    $totals     = $data['totals'] ?? ['qty' => 0, 'amount' => 0];
    $storeName  = $data['store_name'] ?? (auth()->user()?->currentStore?->name ?? config('app.store_name'));
    $storePhone = $data['store_phone'] ?? null;

    $cartIdFormatted = $data['cart_id'] ?? null;
    $storeIdFormatted = $data['store_id'] ?? null;
    $showQr          = $showQr ?? true;
    $qrPath          = $qrPath ?? null;
@endphp

@once
    <style>
        @font-face {
            font-family: 'DejaVu Sans';
            src: url("{{ asset('fonts/DejaVuSans.ttf') }}") format('truetype');
        }
        body {
            font-family: 'DejaVu Sans', monospace;
            font-size: 13px;
            margin: 0;
            padding: 0;
            background: #fff;
            color: #0a0a0a;
        }
        .center { text-align: center; }
        .bold { font-weight: 700; }
        .line { border-bottom: 1px dashed #000; margin: 2mm 0; }
        .item-row {
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            margin: 1mm 0;
            page-break-inside: avoid;
        }
        .item-row .item-name { flex: 1; }
        .item-row .item-total { text-align: right; min-width: 24mm; }
        .receipt-footer-printonly img {
            display: block;
            margin: 0 auto 2mm auto;
            max-width: 40mm;
            max-height: 40mm;
        }
    </style>
@endonce

<div class="center" style="margin-bottom:10px; margin-top:5px;">
    <h3 style="margin:0;">** {{ $storeName }} **</h3>
</div>
<div class="center bold" style="font-size:18px; margin-bottom:6px;">SAVDO CHEKI</div>
<div class="center bold" style="margin-bottom:4px;">{{ $data['receipt_number'] ?? '' }}</div>
<div class="center bold" style="margin-bottom:8px;">{{ $data['date'] ?? '' }}</div>

<div class="bold">Store: #{{ $storeIdFormatted !== null ? $storeIdFormatted : '—' }}</div>
@if(!empty($storePhone))
    <div class="bold">Aloqa uchun: {{ $storePhone }}</div>
@endif
<div style="margin-top:8px; font-size:12px;" class="bold">
    <div>ID: <strong>{{ $meta['sale_id'] ?? '—' }}</strong></div>
    <div>Klient: <strong>{{ $meta['client_name'] ?? '-' }}</strong></div>
    @if(!empty($meta['cashier_name']))
        <div>Kassir: <strong>{{ $meta['cashier_name'] }}</strong></div>
    @endif
    <div>To'lov turi: <strong>{{ $paymentLabel }}</strong></div>
    @if(($paymentType) === 'partial')
        <div>To'langan summa: <strong>{{ number_format((float) ($meta['paid_amount'] ?? 0), 0, '.', ' ') }} so'm</strong></div>
    @endif
    @if(($meta['remaining_amount'] ?? 0) > 0)
        <div>Qolgan qarz: <strong>{{ number_format((float) ($meta['remaining_amount'] ?? 0), 0, '.', ' ') }} so'm</strong></div>
    @endif
</div>

<div class="line"></div>

@foreach($items as $item)
    @php
        $itemName  = $item['name'] ?? 'Mahsulot';
        $size      = $item['size'] ?? null;
        $itemName  = $size ? $itemName . '(' . $size . ')' : $itemName;
        $qty       = (float) ($item['qty'] ?? 0);
        $price     = (float) ($item['price'] ?? 0);
        $lineSubtotal = (float) ($item['subtotal'] ?? ($qty * $price));
        $itemDiscount = (float) ($item['product_discount_total'] ?? 0);
        $lineTotal = (float) ($item['total'] ?? ($lineSubtotal - $itemDiscount));
    @endphp
    <div class="item-row bold">
        <span class="item-name">
            {{ $itemName }}<br>
            <span style="font-size:11px;">{{ rtrim(rtrim(number_format($qty, 2, '.', ''), '0'), '.') }} x {{ number_format($price, 0, '.', ' ') }}</span>
            @if($itemDiscount > 0)
                <br><span style="font-size:11px;">Chegirma: -{{ number_format($itemDiscount, 0, '.', ' ') }} so'm</span>
            @endif
        </span>
        <span class="item-total bold">{{ number_format($lineTotal, 0, '.', ' ') }} so'm</span>
    </div>
@endforeach

<div class="line"></div>

<div class="item-row bold">
    <span>Jami mahsulotlar:</span>
    <span>{{ rtrim(rtrim(number_format((float) ($totals['qty'] ?? 0), 2, '.', ''), '0'), '.') }} dona</span>
</div>
@if(($totals['discount_total'] ?? 0) > 0 || ($totals['customer_discount_amount'] ?? 0) > 0)
    <div class="item-row bold">
        <span>Subtotal:</span>
        <span>{{ number_format((float) ($totals['subtotal'] ?? 0), 0, '.', ' ') }} so'm</span>
    </div>
@endif
@if(($totals['discount_total'] ?? 0) > 0)
    <div class="item-row bold">
        <span>Avtomatik chegirma:</span>
        <span>-{{ number_format((float) ($totals['discount_total'] ?? 0), 0, '.', ' ') }} so'm</span>
    </div>
@endif
@if(($totals['customer_discount_amount'] ?? 0) > 0)
    @php
        $customerDiscountLabel = ($totals['customer_discount_type'] ?? null) === 'percent'
            ? rtrim(rtrim(number_format((float) ($totals['customer_discount_value'] ?? 0), 2, '.', ''), '0'), '.') . '%'
            : number_format((float) ($totals['customer_discount_value'] ?? 0), 0, '.', ' ') . " so'm";
    @endphp
    <div class="item-row bold">
        <span>Mijoz chegirmasi ({{ $customerDiscountLabel }}):</span>
        <span>-{{ number_format((float) $totals['customer_discount_amount'], 0, '.', ' ') }} so'm</span>
    </div>
@endif
<div class="item-row bold" style="font-size:15px;">
    <span>JAMI SUMMA:</span>
    <span>{{ number_format((float) ($totals['amount'] ?? 0), 0, '.', ' ') }} so'm</span>
</div>
<div class="center bold" style="margin-top:10px; font-size:12px;">
    Eslatma: Tovarni 3 kun ichida almashtirish mumkin.<br>
    Sotilgan tovarlar qaytarib olinmaydi.
</div>
<div class="center bold" style="margin-top:18px; font-size:12px;">
    Xaridingiz uchun rahmat!<br>
    Yana tashrifingizni kutamiz
</div>

@if($showQr && $qrPath)
    <div class="receipt-footer-printonly" style="margin-top:12px;">
        <img src="{{ $qrPath }}" alt="QR code" style="max-width:32mm; max-height:32mm;">
    </div>
@endif

<div class="center" style="margin-top:8px; font-size:10px;">
    Tizim hamkori - @oson_pos
</div>
