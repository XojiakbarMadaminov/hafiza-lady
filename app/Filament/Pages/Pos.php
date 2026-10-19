<?php

namespace App\Filament\Pages;

use Filament\Panel;
use App\Models\Sale;
use App\Models\Stock;
use App\Models\Client;
use App\Models\Debtor;
use App\Models\Product;
use App\Models\SaleItem;
use Filament\Pages\Page;
use Livewire\Attributes\On;
use App\Models\ProductStock;
use App\Services\CartService;
use App\Enums\NavigationGroup;
use App\Services\ReturnService;
use Illuminate\Validation\Rule;
use App\Models\DebtorTransaction;
use App\Services\DiscountService;
use App\Services\ExchangeService;
use Livewire\Attributes\Computed;
use Illuminate\Support\Facades\DB;
use Filament\Notifications\Notification;
use App\Services\CustomerDiscountService;
use Filament\Panel\Concerns\HasNotifications;
use Illuminate\Validation\ValidationException;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Illuminate\Support\Collection as EloquentCollection;

class Pos extends Page
{
    use HasNotifications, HasPageShield;

    protected const CLIENT_PHONE_PREFIX = '+998';

    protected static string|null|\UnitEnum $navigationGroup  = NavigationGroup::BaseActions;
    protected static ?string $title                          = 'Sotuv';
    protected string $view                                   = 'filament.pages.pos';
    protected static string|null|\BackedEnum $navigationIcon = 'heroicon-o-shopping-cart';
    protected static ?int $navigationSort                    = 1;

    public static function getRoutePath(Panel $panel): string
    {
        return '/';
    }

    public function getHeading(): string
    {
        return '';
    }

    #[Computed]
    public function canSellWithoutClient(): bool
    {
        $user = auth()->user();

        if (!$user) {
            return false;
        }

        return !$user->currentStore?->always_with_client
            || $user->hasAnyRole(['super_admin', 'admin']);
    }

    public string $search     = '';
    public string $searchById = '';
    public int $activeCartId  = 1; // Joriy faol cart ID
    public bool $showReceipt  = false; // Chek ko'rsatish uchun
    public array $receiptData = []; // Chek ma'lumotlari

    /** @var EloquentCollection<int, Product> */
    public EloquentCollection $products;

    public ?int $expandedVariantProductId = null;

    public array $cart                             = [];
    public array $totals                           = ['qty' => 0, 'amount' => 0];
    public array $activeCarts                      = []; // Barcha faol cartlar ro'yxati
    public array $cartClients                      = [];
    public array $cartPaymentTypes                 = [];
    public array $cartPartialPayments              = [];
    public array $cartMixedPayments                = [];
    public array $cartCustomerDiscounts            = [];
    public array $cartSaleWithoutClient            = [];
    public array $cartSaleWithoutClientPaymentType = [];
    public bool $showClientPanel                   = false;
    public bool $showCreateClientForm              = false;
    public string $searchClient                    = '';
    public $clients                                = [];
    public ?int $selectedClientId                  = null;
    public bool $saleWithoutClient                 = true;
    public string $saleWithoutClientPaymentType    = 'cash';
    public string $paymentType                     = '';
    public ?float $partialPaymentAmount            = null;
    public ?string $paymentNote                    = null;
    public string $customerDiscountType            = CustomerDiscountService::TYPE_PERCENT;
    public ?float $customerDiscountValue           = null;
    public array $mixedPayment                     = [
        'cash' => null,
        'card' => null,
    ];
    protected bool $isSyncingMixedPayment = false;
    public array $stockOptions            = [];

    public bool $showReturnModal   = false;
    public bool $showExchangeModal = false;

    public array $returnForm = [
        'product_id'      => null,
        'product_size_id' => null,
        'stock_id'        => null,
        'quantity'        => 1,
        'price'           => null,
        'reason'          => null,
    ];

    public array $exchangeForm = [
        'in_product_id'       => null,
        'in_product_size_id'  => null,
        'out_product_id'      => null,
        'out_product_size_id' => null,
        'stock_id'            => null,
        'quantity'            => 1,
        'in_price'            => null,
        'out_price'           => null,
        'reason'              => null,
    ];

    public array $returnSizeOptions                 = [];
    public array $exchangeInSizeOptions             = [];
    public array $exchangeOutSizeOptions            = [];
    public string $returnVariantLabel               = 'Razmer';
    public string $exchangeInVariantLabel           = 'Razmer';
    public string $exchangeOutVariantLabel          = 'Razmer';
    public ?int $exchangePriceDifference            = null;
    public string $returnProductSearch              = '';
    public string $exchangeInProductSearch          = '';
    public string $exchangeOutProductSearch         = '';
    public array $returnProductOptions              = [];
    public array $exchangeInProductOptions          = [];
    public array $exchangeOutProductOptions         = [];
    public ?string $returnSelectedProductLabel      = null;
    public ?string $exchangeInSelectedProductLabel  = null;
    public ?string $exchangeOutSelectedProductLabel = null;

    public array $newClient = [
        'full_name' => '',
        'phone'     => self::CLIENT_PHONE_PREFIX,
    ];

    public function mount(): void
    {
        $this->products = new EloquentCollection;

        $this->cartClients                      = session('pos_cart_clients', []);
        $this->cartPaymentTypes                 = session('pos_cart_payment_types', []);
        $this->cartPartialPayments              = session('pos_cart_partial_payments', []);
        $this->cartMixedPayments                = session('pos_cart_mixed_payments', []);
        $this->cartSaleWithoutClient            = session('pos_cart_sale_without_client', []);
        $this->cartSaleWithoutClientPaymentType = session('pos_cart_sale_without_client_payment_type', []);
        $this->cartCustomerDiscounts            = session('pos_cart_customer_discounts', []);
        $this->refreshActiveCarts();

        // Oxirgi faol cart ID ni session dan olish
        $savedCartId = session('pos_active_cart_id', 1);

        // Agar saqlangan cart ID hali ham mavjud bo'lsa, uni ishlatish
        if (!empty($this->activeCarts) && array_key_exists($savedCartId, $this->activeCarts)) {
            $this->activeCartId = $savedCartId;
        } else {
            // Agar saqlangan cart yo'q bo'lsa, birinchi mavjud cartni tanlash
            if (!empty($this->activeCarts)) {
                $this->activeCartId = array_key_first($this->activeCarts);
            } else {
                $this->activeCartId = 1;
            }
            session()->put('pos_active_cart_id', $this->activeCartId);
        }

        $this->loadActiveCartMeta();
        $this->refreshCart();

        $this->stockOptions = Stock::scopes('active')->pluck('name', 'id')->all();
        $defaultStockId     = $this->stockOptions ? array_key_first($this->stockOptions) : null;

        $this->returnForm['stock_id']   = $defaultStockId;
        $this->exchangeForm['stock_id'] = $defaultStockId;

        $this->refreshSelectedProductLabels();
    }

    /* ---------- Cart boshqaruvi ---------- */
    public function switchCart(int $cartId): void
    {
        $this->activeCartId = $cartId;
        // Faol cart ID ni session ga saqlash
        session()->put('pos_active_cart_id', $cartId);
        $this->loadActiveCartMeta();

        $this->refreshCart();
        $this->reset('search', 'searchById');
        $this->products = new EloquentCollection;
    }

    public function createNewCart(): void
    {
        $cartService = app(CartService::class);
        $activeCarts = $cartService->getActiveCartIds();

        // Yangi cart ID ni topish
        $newCartId = 1;
        while (in_array($newCartId, $activeCarts)) {
            $newCartId++;
        }

        $this->activeCartId = $newCartId;
        // Yangi faol cart ID ni session ga saqlash
        session()->put('pos_active_cart_id', $newCartId);
        unset(
            $this->cartClients[$newCartId],
            $this->cartPaymentTypes[$newCartId],
            $this->cartPartialPayments[$newCartId],
            $this->cartMixedPayments[$newCartId],
            $this->cartCustomerDiscounts[$newCartId],
            $this->cartSaleWithoutClient[$newCartId],
            $this->cartSaleWithoutClientPaymentType[$newCartId]
        );
        $this->loadActiveCartMeta();
        $this->persistCartMeta();

        $this->refreshCart();
        $this->refreshActiveCarts();

        Notification::make()
            ->title("Yangi savat #{$newCartId} yaratildi")
            ->success()
            ->send();
    }

    public function closeCart(int $cartId): void
    {
        $cartService = app(CartService::class);

        // Faol cartlar sonini tekshirish (bo'sh cartlarni ham hisobga olish)
        $allActiveCarts = array_keys($this->activeCarts);
        if (count($allActiveCarts) <= 1) {
            Notification::make()
                ->title('Kamida bitta savat ochiq bo\'lishi kerak')
                ->warning()
                ->send();

            return;
        }

        $cartService->clear($cartId);
        unset(
            $this->cartClients[$cartId],
            $this->cartPaymentTypes[$cartId],
            $this->cartPartialPayments[$cartId],
            $this->cartMixedPayments[$cartId],
            $this->cartCustomerDiscounts[$cartId],
            $this->cartSaleWithoutClient[$cartId],
            $this->cartSaleWithoutClientPaymentType[$cartId]
        );

        // Agar yopilayotgan cart joriy faol cart bo'lsa, boshqasini tanlash
        if ($this->activeCartId === $cartId) {
            $remainingCarts     = array_filter($allActiveCarts, fn ($id) => $id !== $cartId);
            $this->activeCartId = reset($remainingCarts) ?: 1;
            session()->put('pos_active_cart_id', $this->activeCartId);
        }

        $this->loadActiveCartMeta();
        $this->persistCartMeta();

        $this->refreshActiveCarts();
        $this->refreshCart();

        Notification::make()
            ->title("Savat #{$cartId} yopildi")
            ->success()
            ->send();
    }

    /* ---------- Qidiruv ---------- */
    public function updatedSearch(): void
    {
        $this->expandedVariantProductId = null;
        $this->searchById               = '';

        if (empty(trim($this->search))) {
            $this->products = new EloquentCollection;

            return;
        }

        $this->products = Product::query()
            ->where(
                fn ($q) => $q->whereLike('barcode', "%{$this->search}%")
                    ->orWhereLike('name', "%{$this->search}%")
            )
            ->orderBy('name')
            ->limit(5)
            ->get();
    }

    public function updatedSearchById(): void
    {
        $this->expandedVariantProductId = null;
        $this->search                   = '';

        $term = trim($this->searchById);

        if ($term === '' || !ctype_digit($term)) {
            $this->products = new EloquentCollection;

            return;
        }

        $this->products = Product::query()
            ->whereKey((int) $term)
            ->limit(1)
            ->get();
    }

    /* ---------- Savat operatsiyalari ---------- */
    public function add(int $id): void
    {
        $product = Product::findOrFail($id);

        if (!$product->isPackageBased()) {
            $this->expandedVariantProductId = null;
            $this->products                 = collect([$product]);

            return;
        }

        app(CartService::class)->add($product, 1, $this->activeCartId);
        $this->refreshCart();
        $this->refreshActiveCarts();
    }

    #[Computed]
    public function posProducts(): EloquentCollection
    {
        return Product::query()
            ->with(['sizes.productStocks', 'productStocks'])
            ->whereKey(array_unique(array_merge(array_keys($this->cart), $this->products->pluck('id')->all())))
            ->get()
            ->keyBy('id');
    }

    #[Computed]
    public function cartLines(): array
    {
        $lines = [];

        foreach ($this->cart as $row) {
            $product = $this->posProducts->get($row['id']);

            if (!$product) {
                continue;
            }

            if ($product->isPackageBased()) {
                $lines[] = array_merge($row, [
                    'variant_id'   => null,
                    'variant_name' => null,
                    'available'    => (int) ($product->productStocks->firstWhere('stock_id', $row['stock_id'])?->quantity ?? 0),
                ]);

                continue;
            }

            foreach ($product->sizes as $variant) {
                $quantity = (int) ($row['sizes'][$variant->id] ?? 0);

                if ($quantity > 0) {
                    $lines[] = array_merge($row, [
                        'variant_id'   => $variant->id,
                        'variant_name' => $variant->size,
                        'qty'          => $quantity,
                        'available'    => (int) ($variant->productStocks->firstWhere('stock_id', $row['stock_id'])?->quantity ?? 0),
                    ]);
                }
            }
        }

        return $lines;
    }

    public function toggleVariantPicker(int $productId): void
    {
        abort_unless(static::canAccess(), 403);

        $product = Product::query()->findOrFail($productId);
        abort_if($product->isPackageBased(), 422);

        $this->expandedVariantProductId = $this->expandedVariantProductId === $productId ? null : $productId;
    }

    public function getVariantOptions(int $productId): array
    {
        $product = $this->posProducts->get($productId);
        $stockId = $this->variantStockId($productId);

        return $product?->sizes->map(fn ($variant): array => [
            'id'        => $variant->id,
            'name'      => $variant->size,
            'available' => (int) ($variant->productStocks->firstWhere('stock_id', $stockId)?->quantity ?? 0),
            'selected'  => (int) ($this->cart[$productId]['sizes'][$variant->id] ?? 0) > 0,
        ])->all() ?? [];
    }

    private function variantStockId(int $productId): ?int
    {
        $stockId = $this->cart[$productId]['stock_id'] ?? null;

        return Stock::query()->active()
            ->when($stockId, fn ($query) => $query->whereKey($stockId))
            ->orderByDesc('is_main')
            ->orderBy('id')
            ->value('id');
    }

    public function addVariant(int $productId, int $variantId): void
    {
        $quantity = (int) (app(CartService::class)->all($this->activeCartId)[$productId]['sizes'][$variantId] ?? 0);
        $this->updateVariantQty($productId, $variantId, $quantity + 1);
    }

    public function updateVariantQty(int $productId, int $variantId, int $quantity): bool
    {
        abort_unless(static::canAccess(), 403);

        $product = Product::query()->findOrFail($productId);
        abort_if($product->isPackageBased(), 422);
        $variant     = $product->sizes()->findOrFail($variantId);
        $cartService = app(CartService::class);
        $cart        = $cartService->all($this->activeCartId);
        $stockId     = $this->variantStockId($productId);
        $available   = (int) ($variant->productStocks()->where('stock_id', $stockId)->value('quantity') ?? 0);

        if ($quantity < 0 || ($quantity > 0 && (!$stockId || $quantity > $available))) {
            Notification::make()->title('Yetarli miqdor yo‘q')->body("Omborda {$available} dona mavjud.")->danger()->send();

            return false;
        }

        $quantities = $cart[$productId]['sizes'] ?? [];

        if ($quantity === 0) {
            unset($quantities[$variantId]);
        } else {
            $quantities[$variantId] = $quantity;
        }

        if (empty($quantities)) {
            $cartService->remove($productId, $this->activeCartId);
        } else {
            if (!isset($cart[$productId])) {
                $cartService->add($product, 1, $this->activeCartId);
                $cartService->updateStock($productId, $stockId, $this->activeCartId);
            }

            $cartService->updateSizes($productId, $quantities, $this->activeCartId);
        }

        unset($this->posProducts, $this->cartLines);
        $this->refreshCart();
        $this->refreshActiveCarts();

        return true;
    }

    public function updateQty(int $id, int $qty)
    {
        $cartService = app(CartService::class);

        $cart = $cartService->all($this->activeCartId);
        $row  = $cart[$id] ?? null;

        if (!$row || empty($row['stock_id'])) {
            Notification::make()
                ->title('Avval skladni tanlang')
                ->danger()
                ->send();

            return false;
        }

        // Only package-based products can be updated directly here
        $product = Product::find($id);
        if (($product?->type ?? 'size') !== 'package') {
            Notification::make()
                ->title('Variantni tanlang')
                ->body('Variantli tovar uchun miqdorni variant qatorida belgilang.')
                ->warning()
                ->send();

            return false;
        }

        $available = ProductStock::whereNull('product_size_id')
            ->where('product_id', $id)
            ->where('stock_id', $row['stock_id'])
            ->value('quantity') ?? 0;

        if ($qty > $available) {
            Notification::make()
                ->title('Yetarli miqdor yo‘q')
                ->body("Skladda faqat {$available} dona mavjud.")
                ->danger()
                ->send();

            return false;
        }

        $cartService->update($id, $qty, $this->activeCartId);

        $this->refreshCart();
        $this->refreshActiveCarts();
    }

    public function remove(int $id): void
    {
        app(CartService::class)->remove($id, $this->activeCartId);
        $this->refreshCart();
        $this->refreshActiveCarts();
    }

    // Paketli mahsulotlar uchun miqdorni yangilash
    public function updatePackageQty(int $id, int $qty)
    {
        $cartService = app(CartService::class);

        $cart = $cartService->all($this->activeCartId);
        $row  = $cart[$id] ?? null;

        if (!$row || empty($row['stock_id'])) {
            Notification::make()
                ->title('Avval skladni tanlang')
                ->danger()
                ->send();

            return false;
        }

        $product = Product::find($id);
        if (($product?->type ?? 'size') !== 'package') {
            Notification::make()
                ->title('Variantni tanlang')
                ->body('Variantli tovar uchun miqdorni variant qatorida belgilang.')
                ->warning()
                ->send();

            return false;
        }

        $available = ProductStock::whereNull('product_size_id')
            ->where('product_id', $id)
            ->where('stock_id', $row['stock_id'])
            ->value('quantity') ?? 0;

        if ($qty > $available) {
            Notification::make()
                ->title('Yetarli miqdor yo‘q')
                ->body("Skladda faqat {$available} dona mavjud.")
                ->danger()
                ->send();

            return false;
        }

        $cartService->update($id, $qty, $this->activeCartId);

        $this->refreshCart();
        $this->refreshActiveCarts();
    }

    public function isPackageProduct(int $productId): bool
    {
        $product = Product::find($productId);

        return ($product?->type ?? 'size') === 'package';
    }

    public function getPackageAvailable(int $productId, int $stockId): int
    {
        return (int) (ProductStock::whereNull('product_size_id')
            ->where('product_id', $productId)
            ->where('stock_id', $stockId)
            ->value('quantity') ?? 0);
    }

    /* ---------- Checkout ---------- */
    public function checkout()
    {
        $cartService = app(CartService::class);
        $cart        = $cartService->all($this->activeCartId);
        $totals      = $this->applyCustomerDiscountToTotals(
            $cartService->totals($this->activeCartId),
            $this->activeCartId
        );

        if (empty($cart)) {
            Notification::make()
                ->title('Savat bo‘sh')
                ->danger()
                ->send();

            return false;
        }

        $baseTotalAmount = round((float) ($totals['amount_before_customer_discount'] ?? $totals['amount'] ?? 0), 2);
        $totalAmount     = round((float) ($totals['amount'] ?? 0), 2);

        if ($baseTotalAmount <= 0) {
            Notification::make()
                ->title('Savat summasi noto‘g‘ri')
                ->body('Savat bo‘sh yoki mahsulot narxlari noto‘g‘ri.')
                ->danger()
                ->send();

            return false;
        }

        if (($totals['customer_discount_amount'] ?? 0) > 0 && !$this->selectedClientId) {
            Notification::make()
                ->title('Mijoz chegirmasi uchun klient talab qilinadi')
                ->warning()
                ->send();

            return false;
        }

        if (!$this->selectedClientId && !$this->canSellWithoutClient) {
            Notification::make()
                ->title('Klient tanlanmagan')
                ->body('Ushbu do‘konda sotuvni yakunlash uchun klient tanlash majburiy.')
                ->warning()
                ->send();

            return false;
        }

        if (!$this->selectedClientId && !$this->saleWithoutClient) {
            Notification::make()
                ->title('Klient tanlanmagan')
                ->body('Checkout qilishdan oldin klientni tanlang.')
                ->warning()
                ->send();

            return false;
        }

        if (!$this->paymentType) {
            Notification::make()
                ->title('To‘lov turi tanlanmagan')
                ->body('Checkout qilishdan oldin to‘lov turini tanlang.')
                ->warning()
                ->send();

            return false;
        }

        $validPaymentTypes = ['cash', 'card', 'debt', 'transfer', 'partial', 'mixed', 'preorder'];

        if (!in_array($this->paymentType, $validPaymentTypes, true)) {
            Notification::make()
                ->title('Noto‘g‘ri to‘lov turi')
                ->danger()
                ->send();

            return false;
        }

        if ($this->paymentType === 'debt' && !$this->selectedClientId) {
            Notification::make()
                ->title('Klient talab qilinadi')
                ->body('Qarzga savdo uchun klientni tanlashingiz kerak.')
                ->warning()
                ->send();

            return false;
        }

        // Klientsiz sotuvda faqat Naqd yoki Karta
        if ($this->saleWithoutClient && !$this->selectedClientId && !in_array($this->paymentType, ['cash', 'card'], true)) {
            Notification::make()
                ->title('Klientsiz sotuv uchun to\'lov turi')
                ->body('Klientsiz sotuv faqat Naqd yoki Karta bilan yakunlanadi.')
                ->warning()
                ->send();

            return false;
        }

        $partialAmount = null;
        $mixedAmounts  = ['cash' => 0.0, 'card' => 0.0];

        if ($this->paymentType === 'partial') {
            $partialAmount = round((float) ($this->partialPaymentAmount ?? 0), 2);

            if ($partialAmount <= 0) {
                Notification::make()
                    ->title('Qisman to‘lov summasi kiritilmagan')
                    ->body('Iltimos, to‘lanadigan summani kiriting.')
                    ->warning()
                    ->send();

                return false;
            }

            if ($partialAmount >= $totalAmount) {
                Notification::make()
                    ->title('Qisman to‘lov summasi noto‘g‘ri')
                    ->body('Qisman to‘lov summasi umumiy summadan kam bo‘lishi kerak.')
                    ->warning()
                    ->send();

                return false;
            }

            $this->cartPartialPayments[$this->activeCartId] = $partialAmount;
            $this->persistCartMeta();
        }

        if ($this->paymentType === 'mixed') {
            $cardAmount = $this->mixedPayment['card'] !== null
                ? round(max(0, (float) $this->mixedPayment['card']), 2)
                : 0.0;

            $cashAmount = $this->mixedPayment['cash'] !== null
                ? round(max(0, (float) $this->mixedPayment['cash']), 2)
                : 0.0;

            $sum = round($cardAmount + $cashAmount, 2);

            if ($sum <= 0) {
                Notification::make()
                    ->title('To‘lov summasi kiritilmagan')
                    ->body('Naqd yoki karta bo‘yicha to‘lovni kiriting.')
                    ->warning()
                    ->send();

                return false;
            }

            if (abs($sum - $totalAmount) > 0.01) {
                Notification::make()
                    ->title('To‘lov summasi mos emas')
                    ->body('Naqd va karta summalari jami savdo summasiga teng bo‘lishi kerak.')
                    ->warning()
                    ->send();

                return false;
            }

            $this->mixedPayment = [
                'card' => $cardAmount > 0 ? $cardAmount : null,
                'cash' => $cashAmount > 0 ? $cashAmount : null,
            ];

            $mixedAmounts = [
                'card' => $cardAmount,
                'cash' => $cashAmount,
            ];

            if ($mixedAmounts['card'] > 0 || $mixedAmounts['cash'] > 0) {
                $this->cartMixedPayments[$this->activeCartId] = $this->mixedPayment;
            } else {
                unset($this->cartMixedPayments[$this->activeCartId]);
            }

            $this->persistCartMeta();
        }

        $preparedItems = [];

        foreach ($cart as $productId => $item) {
            $stockId = $item['stock_id'] ?? null;

            if (!$stockId) {
                Notification::make()
                    ->title('Sklad tanlanmagan')
                    ->body("{$item['name']} uchun sklad tanlang.")
                    ->warning()
                    ->send();

                return false;
            }

            $product = Product::find($productId);
            if ($product && ($product->type ?? 'size') === 'package') {
                $qty = (int) ($item['qty'] ?? 0);
                if ($qty <= 0) {
                    Notification::make()
                        ->title('Miqdor kiritilmadi')
                        ->body("{$item['name']} uchun sotiladigan miqdorni kiriting.")
                        ->warning()
                        ->send();

                    return false;
                }

                $available = ProductStock::whereNull('product_size_id')
                    ->where('product_id', $productId)
                    ->where('stock_id', $stockId)
                    ->value('quantity') ?? 0;

                if ($qty > $available) {
                    Notification::make()
                        ->title('Yetarli miqdor yoq')
                        ->body("{$item['name']} uchun maksimal {$available} dona mavjud.")
                        ->danger()
                        ->send();

                    return false;
                }

                $preparedItems[] = [
                    'product_id'      => $item['id'] ?? $productId,
                    'stock_id'        => $stockId,
                    'product_size_id' => null,
                    'quantity'        => $qty,
                    'price'           => (float) $item['price'],
                    'name'            => $item['name'] ?? 'Mahsulot',
                ];

                // Skip size-based validation for package items
                continue;
            }

            // Razmerli mahsulotlar uchun: sizes yig'indisi har doim prioritet
            $sizes = $item['sizes'] ?? [];

            if (empty($sizes)) {
                $product      = Product::find($item['id'] ?? $productId);
                $variantLabel = $product?->variant_label ?? 'Variant';

                Notification::make()
                    ->title('Variant tanlanmagan')
                    ->body("{$item['name']} uchun {$variantLabel} va miqdorlarni kiriting.")
                    ->warning()
                    ->send();

                return false;
            }

            $hasPositiveQty = false;

            foreach ($sizes as $sizeId => $qty) {
                $qty = (int) $qty;

                if ($qty <= 0) {
                    continue;
                }

                $hasPositiveQty = true;

                $available = ProductStock::where('product_size_id', $sizeId)
                    ->where('stock_id', $stockId)
                    ->value('quantity');

                if ($available === null || $qty > $available) {
                    $size         = \App\Models\ProductSize::find($sizeId);
                    $variantLabel = $size?->product?->variant_label ?? 'Variant';
                    $sizeName     = $size?->size ?? $variantLabel;
                    $availableQty = $available ?? 0;

                    Notification::make()
                        ->title('Yetarli miqdor yoq')
                        ->body("{$item['name']} ({$variantLabel}: {$sizeName}) uchun maksimal {$availableQty} dona mavjud.")
                        ->danger()
                        ->send();

                    return false;
                }

                $preparedItems[] = [
                    'product_id'      => $item['id'] ?? $productId,
                    'stock_id'        => $stockId,
                    'product_size_id' => (int) $sizeId,
                    'quantity'        => $qty,
                    'price'           => (float) $item['price'],
                    'name'            => $item['name'] ?? 'Mahsulot',
                ];
            }

            if (!$hasPositiveQty) {
                Notification::make()
                    ->title('Miqdor kiritilmagan')
                    ->body("{$item['name']} uchun sotiladigan miqdorni kiriting.")
                    ->warning()
                    ->send();

                return false;
            }
        }

        if (empty($preparedItems)) {
            Notification::make()
                ->title('Miqdor kiritilmagan')
                ->body('Har bir mahsulot uchun sotiladigan miqdorni kiriting.')
                ->warning()
                ->send();

            return false;
        }

        $sizeIds = array_values(array_unique(array_filter(array_map(
            function ($item) {
                return $item['product_size_id'] ?? null;
            },
            $preparedItems
        ))));

        $sizeNames = [];
        if (!empty($sizeIds)) {
            $sizeNames = \App\Models\ProductSize::whereIn('id', $sizeIds)->pluck('size', 'id')->toArray();
        }

        $discountSummary         = app(DiscountService::class)->calculate($preparedItems);
        $customerDiscountSummary = app(CustomerDiscountService::class)->calculate(
            (float) $discountSummary['total'],
            $this->selectedClientId ? $this->customerDiscountType : null,
            $this->selectedClientId ? $this->customerDiscountValue : null,
        );
        $totalAmount = $customerDiscountSummary['total'];
        $totals      = array_merge($totals, [
            'amount'                          => $discountSummary['total'],
            'amount_before_customer_discount' => $discountSummary['total'],
            'subtotal'                        => $discountSummary['subtotal'],
            'product_discount_total'          => $discountSummary['product_discount_total'],
            'order_discount_total'            => $discountSummary['order_discount_total'],
            'discount_total'                  => $discountSummary['discount_total'],
            'applied_discounts'               => $discountSummary['applied_discounts'],
            'customer_discount_type'          => $customerDiscountSummary['type'],
            'customer_discount_value'         => $customerDiscountSummary['value'],
            'customer_discount_amount'        => $customerDiscountSummary['amount'],
        ]);
        $totals['amount'] = $totalAmount;

        $receiptItems = [];

        foreach ($preparedItems as $index => $prepared) {
            $sizeId    = $prepared['product_size_id'] ?? null;
            $breakdown = $discountSummary['items'][$index] ?? [];

            $receiptItems[] = [
                'name'                   => $prepared['name'] ?? 'Mahsulot',
                'size'                   => $sizeId ? ($sizeNames[$sizeId] ?? null) : null,
                'qty'                    => (float) $prepared['quantity'],
                'price'                  => (float) $prepared['price'],
                'subtotal'               => (float) ($breakdown['subtotal'] ?? round($prepared['quantity'] * $prepared['price'], 2)),
                'product_discount_total' => (float) ($breakdown['product_discount_total'] ?? 0),
                'total'                  => (float) ($breakdown['total'] ?? round($prepared['quantity'] * $prepared['price'], 2)),
            ];
        }

        $paymentType = $this->paymentType;
        $clientId    = $this->selectedClientId;

        $sale                      = null;
        $remainingAmountForReceipt = 0.0;

        try {
            [$sale, $remainingAmountForReceipt] = DB::transaction(function () use ($preparedItems, $discountSummary, $customerDiscountSummary, $totalAmount, $paymentType, $clientId, $partialAmount, $mixedAmounts) {
                $user    = auth()->user();
                $storeId = $user?->current_store_id;

                if (!$storeId) {
                    throw new \RuntimeException('Foydalanuvchi uchun joriy do‘kon tanlanmagan.');
                }
                $paidAmount = match ($paymentType) {
                    'debt'     => 0.0,
                    'partial'  => $partialAmount ?? 0.0,
                    'mixed'    => $totalAmount,
                    'preorder' => 0.0,
                    default    => $totalAmount,
                };

                $remainingAmount = round($totalAmount - $paidAmount, 2);
                $mixedCash       = $paymentType === 'mixed' ? ($mixedAmounts['cash'] ?? 0.0) : 0.0;
                $mixedCard       = $paymentType === 'mixed' ? ($mixedAmounts['card'] ?? 0.0) : 0.0;
                $status          = $paymentType === 'preorder'
                    ? Sale::STATUS_PENDING
                    : Sale::STATUS_COMPLETED;

                $sale = Sale::create([
                    'cart_id'                  => $this->activeCartId,
                    'client_id'                => $clientId,
                    'subtotal_amount'          => $discountSummary['subtotal'],
                    'total_amount'             => $totalAmount,
                    'product_discount_total'   => $discountSummary['product_discount_total'],
                    'order_discount_total'     => $discountSummary['order_discount_total'],
                    'discount_total'           => $discountSummary['discount_total'],
                    'applied_discounts'        => $discountSummary['applied_discounts'],
                    'customer_discount_type'   => $customerDiscountSummary['type'],
                    'customer_discount_value'  => $customerDiscountSummary['value'],
                    'customer_discount_amount' => $customerDiscountSummary['amount'],
                    'paid_amount'              => $paidAmount,
                    'remaining_amount'         => $remainingAmount,
                    'payment_type'             => $paymentType,
                    'mixed_cash_amount'        => $mixedCash,
                    'mixed_card_amount'        => $mixedCard,
                    'store_id'                 => $storeId,
                    'status'                   => $status,
                    'created_by'               => $user?->id,
                ]);

                foreach ($preparedItems as $index => $prepared) {
                    $breakdown    = $discountSummary['items'][$index] ?? [];
                    $lineSubtotal = (float) ($breakdown['subtotal'] ?? round($prepared['quantity'] * $prepared['price'], 2));
                    $lineDiscount = (float) ($breakdown['product_discount_total'] ?? 0);
                    $lineTotal    = (float) ($breakdown['total'] ?? $lineSubtotal);

                    SaleItem::create([
                        'sale_id'                => $sale->id,
                        'product_id'             => $prepared['product_id'],
                        'stock_id'               => $prepared['stock_id'],
                        'product_size_id'        => $prepared['product_size_id'],
                        'quantity'               => $prepared['quantity'],
                        'price'                  => $prepared['price'],
                        'subtotal_amount'        => $lineSubtotal,
                        'product_discount_total' => $lineDiscount,
                        'total'                  => $lineTotal,
                        'applied_discounts'      => $breakdown['applied_discounts'] ?? [],
                    ]);

                    if (!empty($prepared['product_size_id'])) {
                        ProductStock::where('product_size_id', $prepared['product_size_id'])
                            ->where('stock_id', $prepared['stock_id'])
                            ->decrement('quantity', $prepared['quantity']);
                    } else {
                        ProductStock::whereNull('product_size_id')
                            ->where('product_id', $prepared['product_id'])
                            ->where('stock_id', $prepared['stock_id'])
                            ->decrement('quantity', $prepared['quantity']);
                    }
                }

                if ($remainingAmount > 0 && $paymentType !== 'preorder') {
                    $this->recordSaleDebt($sale, $storeId, $clientId, $remainingAmount);
                }

                return [$sale, $remainingAmount];
            });
        } catch (\Throwable $exception) {
            report($exception);

            Notification::make()
                ->title('Checkout amalga oshmadi')
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return false;
        }

        if ($sale) {
            $sale->loadMissing(['client', 'createdBy']);

            $this->prepareReceipt(
                $sale->store_id,
                $this->activeCartId,
                $receiptItems,
                $totals,
                [
                    'sale_id'          => $sale->id,
                    'client_name'      => $sale->client?->full_name ?? '-',
                    'payment_type'     => $paymentType,
                    'paid_amount'      => $sale->paid_amount,
                    'remaining_amount' => $remainingAmountForReceipt,
                    'cashier_name'     => $sale->createdBy?->name,
                ]
            );

            $this->dispatch('print-receipt', html: $this->receiptHtml());
        }

        $cartService->clear($this->activeCartId);

        unset(
            $this->cartClients[$this->activeCartId],
            $this->cartPaymentTypes[$this->activeCartId],
            $this->cartPartialPayments[$this->activeCartId],
            $this->cartMixedPayments[$this->activeCartId],
            $this->cartCustomerDiscounts[$this->activeCartId],
            $this->cartSaleWithoutClient[$this->activeCartId],
            $this->cartSaleWithoutClientPaymentType[$this->activeCartId]
        );
        $this->persistCartMeta();
        $this->loadActiveCartMeta();

        $this->refreshCart();
        $this->refreshActiveCarts();

        $this->showClientPanel = false;
        $this->paymentNote     = null;

        Notification::make()
            ->title("Savat #{$this->activeCartId} yakunlandi")
            ->success()
            ->send();

        return true;
    }

    protected function recordSaleDebt(Sale $sale, int $storeId, int $clientId, float $remainingAmount): void
    {
        Client::query()
            ->whereKey($clientId)
            ->lockForUpdate()
            ->firstOrFail();

        $debtor = Debtor::query()
            ->where('store_id', $storeId)
            ->where('client_id', $clientId)
            ->oldest('id')
            ->lockForUpdate()
            ->first();

        if (!$debtor) {
            $debtor = Debtor::create([
                'store_id'  => $storeId,
                'client_id' => $clientId,
                'amount'    => 0,
                'currency'  => 'uzs',
                'date'      => now(),
            ]);
        }

        $addedAmount = (int) round($remainingAmount);

        $debtor->increment('amount', $addedAmount);

        DebtorTransaction::create([
            'debtor_id' => $debtor->id,
            'amount'    => $addedAmount,
            'type'      => 'debt',
            'date'      => now(),
            'sale_id'   => $sale->id,
            'note'      => filled($this->paymentNote) ? $this->paymentNote : "Sotuv #{$sale->id}",
        ]);
    }

    /* ---------- Chek funksiyalari ---------- */
    public function prepareReceipt(int $storeId, int $cartId, array $items, array $totals, array $meta = []): void
    {
        $currentStore = auth()->user()?->currentStore;
        $storeName    = $meta['store_name'] ?? $currentStore?->name ?? config('app.store_name');

        $this->receiptData = [
            'store_id'       => $storeId,
            'store_name'     => $storeName,
            'store_phone'    => $currentStore?->phone,
            'cart_id'        => $cartId,
            'items'          => $items,
            'totals'         => $totals,
            'date'           => now()->format('d.m.Y H:i:s'),
            'receipt_number' => 'R' . str_pad($cartId, 4, '0', STR_PAD_LEFT) . time(),
            'meta'           => $meta,
        ];

        $this->showReceipt = false;
    }

    public function printReceipt(): void
    {
        $this->dispatch('print-receipt', html: $this->receiptHtml());
    }

    protected function receiptHtml(): string
    {
        return view('receipts.partials.default', [
            'receiptData' => $this->receiptData,
            'qrPath'      => asset('images/taplink.png'),
        ])->render();
    }

    public function closeReceipt(): void
    {
        $this->showReceipt = false;
        $this->receiptData = [];
    }

    /* ---------- Helper metodlar ---------- */
    #[
        On('refresh-cart')]
    public function refreshCart(): void
    {
        unset($this->posProducts, $this->cartLines);

        $cartService  = app(CartService::class);
        $this->cart   = $cartService->all($this->activeCartId);
        $this->totals = $this->applyCustomerDiscountToTotals(
            $cartService->totals($this->activeCartId),
            $this->activeCartId
        );

        if (
            $this->paymentType === 'mixed'
            && (($this->mixedPayment['card'] ?? null) !== null || ($this->mixedPayment['cash'] ?? null) !== null)
        ) {
            $this->handleMixedPaymentUpdate('card', $this->mixedPayment['card']);
        }
    }

    public function refreshActiveCarts(): void
    {
        $cartService       = app(CartService::class);
        $this->activeCarts = [];

        // Barcha mavjud cartlarni olish (bo'sh ham, to'la ham)
        $allCartIds = $cartService->getAllCartIds();

        if (empty($allCartIds)) {
            // Agar hech qanday cart bo'lmasa, birinchi cartni yaratish
            $this->activeCarts[1] = ['qty' => 0, 'amount' => 0];
        } else {
            // Barcha cartlar uchun ma'lumotlarni olish
            foreach ($allCartIds as $cartId) {
                $this->activeCarts[$cartId] = $this->applyCustomerDiscountToTotals(
                    $cartService->totals($cartId),
                    $cartId
                );
            }
        }
    }

    protected function loadActiveCartMeta(): void
    {
        $this->selectedClientId             = $this->cartClients[$this->activeCartId] ?? null;
        $this->paymentType                  = $this->cartPaymentTypes[$this->activeCartId] ?? '';
        $this->saleWithoutClient            = (bool) ($this->cartSaleWithoutClient[$this->activeCartId] ?? true);
        $this->saleWithoutClientPaymentType = (string) ($this->cartSaleWithoutClientPaymentType[$this->activeCartId] ?? 'cash');

        if (!$this->canSellWithoutClient) {
            $this->saleWithoutClient                          = false;
            $this->cartSaleWithoutClient[$this->activeCartId] = false;
        }

        if ($this->saleWithoutClient && !$this->selectedClientId) {
            if (!in_array($this->paymentType, ['cash', 'card'], true)) {
                $this->paymentType = in_array($this->saleWithoutClientPaymentType, ['cash', 'card'], true)
                    ? $this->saleWithoutClientPaymentType
                    : 'cash';
                $this->cartPaymentTypes[$this->activeCartId] = $this->paymentType;
            }
        }

        $partialValue = $this->cartPartialPayments[$this->activeCartId] ?? null;
        if (is_array($partialValue)) {
            $partialValue = array_sum(array_filter(
                $partialValue,
                fn ($v) => is_numeric($v)
            ));
        }
        $this->partialPaymentAmount = $partialValue !== null ? (float) $partialValue : null;

        $mixed = $this->cartMixedPayments[$this->activeCartId] ?? ['cash' => null, 'card' => null];
        if (!is_array($mixed)) {
            $mixed = ['cash' => null, 'card' => null];
        }
        $this->mixedPayment = [
            'cash' => $mixed['cash'] ?? null,
            'card' => $mixed['card'] ?? null,
        ];

        $customerDiscount           = $this->cartCustomerDiscounts[$this->activeCartId] ?? [];
        $this->customerDiscountType = in_array(
            $customerDiscount['type'] ?? null,
            [CustomerDiscountService::TYPE_PERCENT, CustomerDiscountService::TYPE_FIXED],
            true
        ) ? $customerDiscount['type'] : CustomerDiscountService::TYPE_PERCENT;
        $this->customerDiscountValue = isset($customerDiscount['value']) && is_numeric($customerDiscount['value'])
            ? (float) $customerDiscount['value']
            : null;
    }

    protected function persistCartMeta(): void
    {
        session()->put('pos_cart_clients', $this->cartClients);
        session()->put('pos_cart_payment_types', $this->cartPaymentTypes);
        session()->put('pos_cart_sale_without_client', $this->cartSaleWithoutClient);
        session()->put('pos_cart_sale_without_client_payment_type', $this->cartSaleWithoutClientPaymentType);
        session()->put('pos_cart_customer_discounts', $this->cartCustomerDiscounts);

        $partialNormalized = [];
        foreach ($this->cartPartialPayments as $cartId => $value) {
            if (is_array($value)) {
                $value = array_sum(array_filter($value, fn ($v) => is_numeric($v)));
            }
            $partialNormalized[$cartId] = $value;
        }
        $this->cartPartialPayments = $partialNormalized;
        session()->put('pos_cart_partial_payments', $partialNormalized);

        $mixedNormalized = [];
        foreach ($this->cartMixedPayments as $cartId => $values) {
            if (!is_array($values)) {
                $values = ['cash' => null, 'card' => null];
            }

            $mixedNormalized[$cartId] = [
                'cash' => $values['cash'] ?? null,
                'card' => $values['card'] ?? null,
            ];
        }

        $this->cartMixedPayments = $mixedNormalized;
        session()->put('pos_cart_mixed_payments', $mixedNormalized);
    }

    /**
     * @param  array<string, mixed>  $totals
     * @return array<string, mixed>
     */
    protected function applyCustomerDiscountToTotals(array $totals, int $cartId): array
    {
        $baseAmount = round((float) ($totals['amount'] ?? 0), 2);
        $clientId   = $this->cartClients[$cartId] ?? null;
        $discount   = $this->cartCustomerDiscounts[$cartId] ?? [];

        $summary = app(CustomerDiscountService::class)->calculate(
            $baseAmount,
            $clientId ? ($discount['type'] ?? null) : null,
            $clientId ? ($discount['value'] ?? null) : null,
        );

        return array_merge($totals, [
            'amount_before_customer_discount' => $baseAmount,
            'customer_discount_type'          => $summary['type'],
            'customer_discount_value'         => $summary['value'],
            'customer_discount_amount'        => $summary['amount'],
            'amount'                          => $summary['total'],
        ]);
    }

    public function updatedCustomerDiscountType(mixed $value): void
    {
        $this->customerDiscountType = in_array(
            $value,
            [CustomerDiscountService::TYPE_PERCENT, CustomerDiscountService::TYPE_FIXED],
            true
        ) ? $value : CustomerDiscountService::TYPE_PERCENT;

        if ($this->customerDiscountValue !== null) {
            $this->updatedCustomerDiscountValue($this->customerDiscountValue);

            return;
        }

        $this->syncCustomerDiscount();
    }

    public function updatedCustomerDiscountValue(mixed $value): void
    {
        if ($value === null || $value === '') {
            $this->customerDiscountValue = null;
            $this->syncCustomerDiscount();

            return;
        }

        $baseAmount = (float) ($this->totals['amount_before_customer_discount'] ?? $this->totals['amount'] ?? 0);
        $summary    = app(CustomerDiscountService::class)->calculate(
            $baseAmount,
            $this->customerDiscountType,
            $value,
        );

        $this->customerDiscountValue = $summary['value'];
        $this->syncCustomerDiscount();
    }

    protected function syncCustomerDiscount(): void
    {
        if (!$this->selectedClientId) {
            $this->resetCustomerDiscount();

            return;
        }

        $this->cartCustomerDiscounts[$this->activeCartId] = [
            'type'  => $this->customerDiscountType,
            'value' => $this->customerDiscountValue,
        ];

        $this->persistCartMeta();
        $this->recalculateCustomerDiscountTotals();
    }

    protected function resetCustomerDiscount(bool $persist = true): void
    {
        $this->customerDiscountType  = CustomerDiscountService::TYPE_PERCENT;
        $this->customerDiscountValue = null;
        unset($this->cartCustomerDiscounts[$this->activeCartId]);

        if ($persist) {
            $this->persistCartMeta();
            $this->recalculateCustomerDiscountTotals();
        }
    }

    protected function recalculateCustomerDiscountTotals(): void
    {
        $baseAmount = (float) ($this->totals['amount_before_customer_discount'] ?? $this->totals['amount'] ?? 0);
        $discount   = $this->cartCustomerDiscounts[$this->activeCartId] ?? [];

        $summary = app(CustomerDiscountService::class)->calculate(
            $baseAmount,
            $this->selectedClientId ? ($discount['type'] ?? null) : null,
            $this->selectedClientId ? ($discount['value'] ?? null) : null,
        );

        $this->totals = array_merge($this->totals, [
            'amount_before_customer_discount' => $baseAmount,
            'customer_discount_type'          => $summary['type'],
            'customer_discount_value'         => $summary['value'],
            'customer_discount_amount'        => $summary['amount'],
            'amount'                          => $summary['total'],
        ]);
        $this->activeCarts[$this->activeCartId] = $this->totals;

        if (
            $this->paymentType === 'mixed'
            && (($this->mixedPayment['card'] ?? null) !== null || ($this->mixedPayment['cash'] ?? null) !== null)
        ) {
            $this->handleMixedPaymentUpdate('card', $this->mixedPayment['card']);
        }
    }

    public function updatedSaleWithoutClient($value): void
    {
        $this->saleWithoutClient = $this->canSellWithoutClient && (bool) $value;

        $this->cartSaleWithoutClient[$this->activeCartId] = $this->saleWithoutClient;

        if ($this->saleWithoutClient && !$this->selectedClientId) {
            $type = in_array($this->saleWithoutClientPaymentType, ['cash', 'card'], true)
                ? $this->saleWithoutClientPaymentType
                : 'cash';
            $this->paymentType                           = $type;
            $this->cartPaymentTypes[$this->activeCartId] = $type;
        }

        $this->persistCartMeta();
    }

    public function updatedSaleWithoutClientPaymentType($value): void
    {
        $val                                                         = in_array($value, ['cash', 'card'], true) ? $value : 'cash';
        $this->saleWithoutClientPaymentType                          = $val;
        $this->cartSaleWithoutClientPaymentType[$this->activeCartId] = $val;

        if ($this->saleWithoutClient && !$this->selectedClientId) {
            $this->paymentType                           = $val;
            $this->cartPaymentTypes[$this->activeCartId] = $val;
        }

        $this->persistCartMeta();
    }

    /* ---------- Skaner metodlari ---------- */
    public function scanEnter(): void
    {
        $code = trim($this->search);
        if (!$code) {
            return;
        }

        $product = Product::where('barcode', $code)->first();
        if ($product) {
            $this->add($product->id);
            $this->reset('search');
        } else {
            Notification::make()
                ->title('Mahsulot topilmadi')
                ->danger()
                ->send();
        }
    }

    public function addByBarcode(string $value): void
    {
        $value = trim($value);
        if (!$value) {
            return;
        }

        $product = Product::where('barcode', $value)
            ->orWhere(function ($q) use ($value) {
                $q->whereLike('name', "{$value}%")
                    ->orWhereLike('name', "%{$value}");
            })
            ->first();

        if ($product) {
            if (!$product->isPackageBased()) {
                $this->add($product->id);

                return;
            }

            app(CartService::class)->add($product, 1, $this->activeCartId);
            $this->reset('search');
            $this->products = new EloquentCollection;
            $this->refreshCart();
            $this->refreshActiveCarts();

            Notification::make()
                ->title("Savat #{$this->activeCartId} ga qo'shildi")
                ->success()
                ->send();
        } else {
            Notification::make()
                ->title('Mahsulot topilmadi')
                ->danger()
                ->send();
        }
    }

    public function addByProductId(string $value): void
    {
        $value = trim($value);

        if ($value === '' || !ctype_digit($value)) {
            return;
        }

        $product = Product::query()
            ->whereKey((int) $value)
            ->first();

        if ($product) {
            if (!$product->isPackageBased()) {
                $this->add($product->id);

                return;
            }

            app(CartService::class)->add($product, 1, $this->activeCartId);
            $this->reset('searchById');
            $this->products = new EloquentCollection;
            $this->refreshCart();
            $this->refreshActiveCarts();

            Notification::make()
                ->title("Savat #{$this->activeCartId} ga qo'shildi")
                ->success()
                ->send();
        } else {
            Notification::make()
                ->title('Mahsulot topilmadi')
                ->danger()
                ->send();
        }
    }

    public function updatePrice(int $id, float $price)
    {
        try {
            app(CartService::class)->updatePrice($id, $price, $this->activeCartId);
            $this->refreshCart();
            $this->refreshActiveCarts();

            return true;
        } catch (\InvalidArgumentException $e) {
            Notification::make()
                ->title('Narx noto‘g‘ri')
                ->body($e->getMessage())
                ->danger()
                ->send();

            return false;
        }
    }

    public function updateStock(int $productId, int $stockId): void
    {
        app(CartService::class)->updateStock($productId, $stockId, $this->activeCartId);

        $this->refreshCart();
        $this->refreshActiveCarts();
    }

    // 🔹 Mahsulot razmerlarini olish (modal uchun)
    public function getProductSizes($productId)
    {
        $product = Product::with('sizes')->findOrFail($productId);

        $sizes = $product->sizes->map(fn ($s) => [
            'id'        => $s->id,
            'name'      => $s->size,
            'available' => ProductStock::where('product_size_id', $s->id)
                ->where('stock_id', $this->activeStockId ?? 1)
                ->value('quantity') ?? 0,
        ]);

        return [
            'product' => [
                'id'                   => $product->id,
                'name'                 => $product->name,
                'variant_label'        => $product->variant_label,
                'variant_plural_label' => $product->variant_plural_label,
            ],
            'sizes'      => $sizes,
            'quantities' => $this->cart[$productId]['sizes'] ?? [],
        ];
    }

    public function updateSizes(int $productId, array $sizes)
    {
        $cartService = app(CartService::class);
        $cart        = $cartService->all($this->activeCartId);
        $row         = $cart[$productId] ?? null;

        if (!$row || empty($row['stock_id'])) {
            Notification::make()
                ->title('Avval skladni tanlang')
                ->danger()
                ->send();

            return false;
        }

        $stockId = $row['stock_id'];

        foreach ($sizes as $sizeId => $qty) {
            if ($qty <= 0) {
                continue;
            }

            $size      = \App\Models\ProductSize::find($sizeId);
            $available = ProductStock::where('product_size_id', $sizeId)
                ->where('stock_id', $stockId)
                ->value('quantity');

            if ($qty > $available) {
                $variantLabel = $size?->product?->variant_label ?? 'Variant';

                Notification::make()
                    ->title('Yetarli miqdor yo‘q')
                    ->body("{$variantLabel}: <b>{$size->size}</b> uchun faqat <b>{$available}</b> dona mavjud.")
                    ->danger()
                    ->send();

                return false;
            }
        }

        // 🔹 Hammasi to‘g‘ri — saqlaymiz
        $cartService->updateSizes($productId, $sizes, $this->activeCartId);

        $this->refreshCart();
        $this->refreshActiveCarts();
    }

    /* === Klient panelini ochish === */
    public function openClientPanel(): void
    {
        $this->showClientPanel = true;
        $this->loadClients();
    }

    /* === Klientlarni qidirish === */
    public function loadClients(): void
    {
        $term = trim($this->searchClient);

        $clients = Client::query()
            ->when($term, function ($query) use ($term) {
                $query->where(function ($inner) use ($term) {
                    $inner->where('full_name', 'ilike', "%{$term}%")
                        ->orWhere('phone', 'ilike', "%{$term}%");
                });
            })
            ->orderBy('full_name')
            ->limit(4)
            ->get();

        if ($this->selectedClientId) {
            $selectedClient = $clients->firstWhere('id', $this->selectedClientId)
                ?? Client::find($this->selectedClientId);

            if ($selectedClient) {
                $clients = $clients
                    ->reject(fn ($client) => $client->id === $selectedClient->id)
                    ->prepend($selectedClient)
                    ->values();
            }
        }

        $this->clients = $clients;
    }

    public function updatedSearchClient(): void
    {
        $this->loadClients();
    }

    /* === Klient tanlash === */
    public function selectClient(int $id): void
    {
        if ($this->selectedClientId !== $id) {
            $this->resetCustomerDiscount(false);
        }

        $this->selectedClientId                 = $id;
        $this->cartClients[$this->activeCartId] = $id;
        // When a client is selected, disable 'klientsiz sotuv' mode
        $this->saleWithoutClient                          = false;
        $this->cartSaleWithoutClient[$this->activeCartId] = false;
        $this->persistCartMeta();
        $this->refreshCart();
        $this->refreshActiveCarts();
    }

    /* === Yangi klient formasi === */
    public function toggleCreateClientForm(): void
    {
        $this->showCreateClientForm = !$this->showCreateClientForm;

        // Formani ochganda eski ma'lumotlarni tozalash
        if ($this->showCreateClientForm) {
            $this->newClient = $this->getDefaultNewClientData();
        }
    }

    public function updatedNewClientPhone(mixed $value): void
    {
        $this->newClient['phone'] = $this->normalizeClientPhoneForInput($value);
    }

    /* === Yangi klient yaratish === */
    public function createClient(): void
    {
        $phone = $this->normalizeClientPhoneForStorage($this->newClient['phone'] ?? null);

        $this->newClient['phone'] = $phone;

        $this->validate([
            'newClient.full_name' => 'required|string|min:3',
            'newClient.phone'     => [
                'required',
                'string',
                'regex:/^\+998\d{9}$/',
                Rule::unique('clients', 'phone'),
            ],
        ], [
            'newClient.full_name.required' => 'To\'liq ismni kiriting',
            'newClient.full_name.min'      => 'Ism kamida 3 ta belgidan iborat bo\'lishi kerak',
            'newClient.phone.required'     => 'Telefon raqamni kiriting.',
            'newClient.phone.regex'        => '+998 dan keyin 9 ta raqam kiriting.',
            'newClient.phone.unique'       => 'Ushbu telefon raqami allaqachon mavjud.',
        ]);

        $client = Client::create([
            'full_name' => $this->newClient['full_name'],
            'phone'     => $phone,
        ]);

        $this->resetCustomerDiscount(false);
        $this->selectedClientId                           = $client->id;
        $this->cartClients[$this->activeCartId]           = $client->id;
        $this->saleWithoutClient                          = false;
        $this->cartSaleWithoutClient[$this->activeCartId] = false;
        $this->newClient                                  = $this->getDefaultNewClientData();
        $this->showCreateClientForm                       = false;
        $this->loadClients();
        $this->persistCartMeta();
        $this->refreshCart();
        $this->refreshActiveCarts();

        Notification::make()
            ->title('Yangi klient yaratildi')
            ->body("{$client->full_name} qo'shildi va tanlandi")
            ->success()
            ->send();
    }

    /**
     * @return array{full_name: string, phone: string}
     */
    protected function getDefaultNewClientData(): array
    {
        return [
            'full_name' => '',
            'phone'     => self::CLIENT_PHONE_PREFIX,
        ];
    }

    protected function normalizeClientPhoneForInput(mixed $value): string
    {
        $digits = preg_replace('/\D+/', '', (string) $value) ?? '';

        if (str_starts_with($digits, '998')) {
            $digits = substr($digits, 3);
        }

        $digits = substr($digits, 0, 9);

        return self::CLIENT_PHONE_PREFIX . $digits;
    }

    protected function normalizeClientPhoneForStorage(mixed $value): string
    {
        return $this->normalizeClientPhoneForInput($value);
    }

    /* === To'lov turini tanlash === */
    public function selectPaymentType(string $type): void
    {
        if (!in_array($type, ['cash', 'card', 'debt', 'transfer', 'partial', 'mixed', 'preorder'])) {
            Notification::make()
                ->title('Noto\'g\'ri to\'lov turi')
                ->danger()
                ->send();

            return;
        }

        if ($this->saleWithoutClient && !$this->selectedClientId && !in_array($type, ['cash', 'card'], true)) {
            Notification::make()
                ->title('Klientsiz sotuv uchun to\'lov turi')
                ->body('Klientsiz sotuv faqat Naqd yoki Karta bilan yakunlanadi.')
                ->warning()
                ->send();

            return;
        }

        $this->paymentType                           = $type;
        $this->cartPaymentTypes[$this->activeCartId] = $type;

        if (in_array($type, ['cash', 'card'], true)) {
            $this->saleWithoutClientPaymentType                          = $type;
            $this->cartSaleWithoutClientPaymentType[$this->activeCartId] = $type;
        }

        if ($type === 'partial') {
            $this->partialPaymentAmount = $this->cartPartialPayments[$this->activeCartId] ?? null;
            $this->mixedPayment         = ['cash' => null, 'card' => null];
            // keep note for partial
        } elseif ($type === 'mixed') {
            $stored = $this->cartMixedPayments[$this->activeCartId] ?? ['cash' => null, 'card' => null];
            if (!is_array($stored)) {
                $stored = ['cash' => null, 'card' => null];
            }
            $this->mixedPayment = [
                'cash' => $stored['cash'] ?? null,
                'card' => $stored['card'] ?? null,
            ];
            $this->partialPaymentAmount = null;
            $this->handleMixedPaymentUpdate('card', $this->mixedPayment['card']);
            $this->paymentNote = null; // not used for mixed
        } else {
            $this->partialPaymentAmount = null;
            $this->mixedPayment         = ['cash' => null, 'card' => null];
            unset(
                $this->cartPartialPayments[$this->activeCartId],
                $this->cartMixedPayments[$this->activeCartId]
            );
            if (!in_array($type, ['debt', 'partial', 'preorder'])) {
                $this->paymentNote = null;
            }
        }

        $this->persistCartMeta();
    }

    public function updatedPartialPaymentAmount($value): void
    {
        if ($this->paymentType !== 'partial') {
            $this->partialPaymentAmount = null;

            return;
        }

        if ($value === null || $value === '') {
            $this->partialPaymentAmount = null;
            unset($this->cartPartialPayments[$this->activeCartId]);
            $this->persistCartMeta();

            return;
        }

        $normalized  = round(max(0, (float) $value), 2);
        $totalAmount = round((float) ($this->totals['amount'] ?? 0), 2);

        if ($totalAmount > 0 && $normalized >= $totalAmount) {
            $normalized = max($totalAmount - 1000.00, 0);
        }

        $this->partialPaymentAmount = $normalized;

        if ($normalized > 0) {
            $this->cartPartialPayments[$this->activeCartId] = $normalized;
        } else {
            unset($this->cartPartialPayments[$this->activeCartId]);
        }

        $this->persistCartMeta();
    }

    public function updatedMixedPaymentCard($value): void
    {
        $this->handleMixedPaymentUpdate('card', $value);
    }

    public function updatedMixedPaymentCash($value): void
    {
        $this->handleMixedPaymentUpdate('cash', $value);
    }

    protected function handleMixedPaymentUpdate(string $type, $value): void
    {
        if ($this->paymentType !== 'mixed') {
            $this->mixedPayment = ['cash' => null, 'card' => null];

            return;
        }

        if (!in_array($type, ['cash', 'card'], true)) {
            return;
        }

        if ($this->isSyncingMixedPayment) {
            return;
        }

        $this->isSyncingMixedPayment = true;

        try {
            $totalAmount = round((float) ($this->totals['amount'] ?? 0), 2);
            $normalized  = $this->normalizeMixedPaymentValue($value);

            if ($type === 'card') {
                $cardAmount = $normalized ?? 0.0;

                if ($totalAmount > 0 && $cardAmount > $totalAmount) {
                    $cardAmount = $totalAmount;
                }

                $cashAmount = $totalAmount > 0
                    ? round(max($totalAmount - $cardAmount, 0), 2)
                    : 0.0;

                $this->mixedPayment['card'] = $cardAmount > 0 ? $cardAmount : null;
                $this->mixedPayment['cash'] = $cashAmount > 0 ? $cashAmount : null;
            } else {
                $cashAmount = $normalized ?? 0.0;

                if ($totalAmount > 0 && $cashAmount > $totalAmount) {
                    $cashAmount = $totalAmount;
                }

                $cardAmount = $totalAmount > 0
                    ? round(max($totalAmount - $cashAmount, 0), 2)
                    : 0.0;

                $this->mixedPayment['cash'] = $cashAmount > 0 ? $cashAmount : null;
                $this->mixedPayment['card'] = $cardAmount > 0 ? $cardAmount : null;
            }

            if (($this->mixedPayment['cash'] ?? null) === null && ($this->mixedPayment['card'] ?? null) === null) {
                unset($this->cartMixedPayments[$this->activeCartId]);
            } else {
                $this->cartMixedPayments[$this->activeCartId] = $this->mixedPayment;
            }

            $this->persistCartMeta();
        } finally {
            $this->isSyncingMixedPayment = false;
        }
    }

    public function openReturnModal(): void
    {
        $this->resetErrorBag();
        $this->showReturnModal      = true;
        $this->returnProductOptions = $this->buildProductOptions($this->returnProductSearch);
        $this->refreshSelectedProductLabels();

        if ($productId = $this->returnForm['product_id']) {
            $this->loadReturnProductMeta((int) $productId);
        }
    }

    public function closeReturnModal(): void
    {
        $this->showReturnModal = false;
        $this->resetErrorBag();
    }

    public function openExchangeModal(): void
    {
        $this->resetErrorBag();
        $this->showExchangeModal         = true;
        $this->exchangeInProductOptions  = $this->buildProductOptions($this->exchangeInProductSearch);
        $this->exchangeOutProductOptions = $this->buildProductOptions($this->exchangeOutProductSearch);
        $this->refreshSelectedProductLabels();

        if ($productId = $this->exchangeForm['in_product_id']) {
            $this->loadExchangeProductMeta('in', (int) $productId);
        }

        if ($productId = $this->exchangeForm['out_product_id']) {
            $this->loadExchangeProductMeta('out', (int) $productId);
        }
    }

    public function closeExchangeModal(): void
    {
        $this->showExchangeModal = false;
        $this->resetErrorBag();
    }

    public function updatedReturnFormProductId($value): void
    {
        $this->loadReturnProductMeta((int) $value);
        $this->returnSelectedProductLabel = $this->getProductLabel((int) $value);
    }

    public function updatedExchangeFormInProductId($value): void
    {
        $this->loadExchangeProductMeta('in', (int) $value);
        $this->exchangeInSelectedProductLabel = $this->getProductLabel((int) $value);
    }

    public function updatedExchangeFormOutProductId($value): void
    {
        $this->loadExchangeProductMeta('out', (int) $value);
        $this->exchangeOutSelectedProductLabel = $this->getProductLabel((int) $value);
    }

    public function updatedExchangeFormQuantity($value): void
    {
        $this->exchangeForm['quantity'] = (int) $value;
        $this->recalcExchangeDifference();
    }

    public function updatedExchangeFormInPrice($value): void
    {
        $this->exchangeForm['in_price'] = $value;
        $this->recalcExchangeDifference();
    }

    public function updatedExchangeFormOutPrice($value): void
    {
        $this->exchangeForm['out_price'] = $value;
        $this->recalcExchangeDifference();
    }

    public function updatedReturnProductSearch(): void
    {
        $this->returnProductOptions = $this->buildProductOptions($this->returnProductSearch);
    }

    public function updatedExchangeInProductSearch(): void
    {
        $this->exchangeInProductOptions = $this->buildProductOptions($this->exchangeInProductSearch);
    }

    public function updatedExchangeOutProductSearch(): void
    {
        $this->exchangeOutProductOptions = $this->buildProductOptions($this->exchangeOutProductSearch);
    }

    public function selectReturnProduct(int $productId): void
    {
        $label = $this->getProductLabel($productId);

        if (!$label) {
            Notification::make()
                ->title('Mahsulot topilmadi')
                ->danger()
                ->send();

            return;
        }

        $this->returnForm['product_id']   = $productId;
        $this->returnSelectedProductLabel = $label;
        $this->returnProductSearch        = '';
        $this->returnProductOptions       = [];
        $this->loadReturnProductMeta($productId);
        $this->refreshSelectedProductLabels();
    }

    public function clearReturnProductSelection(): void
    {
        $this->returnForm['product_id']      = null;
        $this->returnForm['product_size_id'] = null;
        $this->returnForm['price']           = null;
        $this->returnSelectedProductLabel    = null;
        $this->returnProductSearch           = '';
        $this->returnSizeOptions             = [];
        $this->refreshSelectedProductLabels();
    }

    public function selectExchangeInProduct(int $productId): void
    {
        $label = $this->getProductLabel($productId);

        if (!$label) {
            Notification::make()
                ->title('Mahsulot topilmadi')
                ->danger()
                ->send();

            return;
        }

        $this->exchangeForm['in_product_id']  = $productId;
        $this->exchangeInSelectedProductLabel = $label;
        $this->exchangeInProductSearch        = '';
        $this->exchangeInProductOptions       = [];
        $this->loadExchangeProductMeta('in', $productId);
        $this->refreshSelectedProductLabels();
    }

    public function clearExchangeInProductSelection(): void
    {
        $this->exchangeForm['in_product_id']      = null;
        $this->exchangeForm['in_product_size_id'] = null;
        $this->exchangeForm['in_price']           = null;
        $this->exchangeInSelectedProductLabel     = null;
        $this->exchangeInProductSearch            = '';
        $this->exchangeInSizeOptions              = [];
        $this->refreshSelectedProductLabels();
    }

    public function selectExchangeOutProduct(int $productId): void
    {
        $label = $this->getProductLabel($productId);

        if (!$label) {
            Notification::make()
                ->title('Mahsulot topilmadi')
                ->danger()
                ->send();

            return;
        }

        $this->exchangeForm['out_product_id']  = $productId;
        $this->exchangeOutSelectedProductLabel = $label;
        $this->exchangeOutProductSearch        = '';
        $this->exchangeOutProductOptions       = [];
        $this->loadExchangeProductMeta('out', $productId);
        $this->refreshSelectedProductLabels();
    }

    public function clearExchangeOutProductSelection(): void
    {
        $this->exchangeForm['out_product_id']      = null;
        $this->exchangeForm['out_product_size_id'] = null;
        $this->exchangeForm['out_price']           = null;
        $this->exchangeOutSelectedProductLabel     = null;
        $this->exchangeOutProductSearch            = '';
        $this->exchangeOutSizeOptions              = [];
        $this->refreshSelectedProductLabels();
    }

    public function submitReturn(): void
    {
        $form = $this->validateReturnForm()['returnForm'];

        try {
            app(ReturnService::class)->handle([
                'product_id'      => $form['product_id'],
                'product_size_id' => $form['product_size_id'],
                'stock_id'        => $form['stock_id'],
                'quantity'        => $form['quantity'],
                'price'           => $form['price'],
                'reason'          => $form['reason'],
            ]);
        } catch (ValidationException $exception) {
            $this->applyFormErrors('returnForm', $exception->errors());

            return;
        }

        Notification::make()
            ->title('Mahsulot qaytarildi')
            ->body('Ombordagi qoldiq yangilandi va naqd balans kamaytirildi.')
            ->success()
            ->send();

        $this->resetReturnForm();
        $this->showReturnModal = false;
    }

    public function submitExchange(): void
    {
        $form = $this->validateExchangeForm()['exchangeForm'];

        try {
            $operation = app(ExchangeService::class)->handle([
                'in_product_id'       => $form['in_product_id'],
                'in_product_size_id'  => $form['in_product_size_id'],
                'out_product_id'      => $form['out_product_id'],
                'out_product_size_id' => $form['out_product_size_id'],
                'stock_id'            => $form['stock_id'],
                'quantity'            => $form['quantity'],
                'in_price'            => $form['in_price'],
                'out_price'           => $form['out_price'],
                'reason'              => $form['reason'],
            ]);
        } catch (ValidationException $exception) {
            $this->applyFormErrors('exchangeForm', $exception->errors());

            return;
        }

        $this->exchangePriceDifference = $operation->price_difference;

        $diffMessage = match (true) {
            $this->exchangePriceDifference > 0 => 'Mijozdan farq summasi qabul qilindi.',
            $this->exchangePriceDifference < 0 => 'Mijozga farq summasi qaytarildi.',
            default                            => 'Narx farqi mavjud emas.',
        };

        Notification::make()
            ->title('Almashinuv yakunlandi')
            ->body($diffMessage)
            ->success()
            ->send();

        $this->resetExchangeForm();
        $this->showExchangeModal = false;
    }

    protected function validateReturnForm(): array
    {
        return $this->validate([
            'returnForm.product_id'      => ['required', 'integer', 'exists:products,id'],
            'returnForm.stock_id'        => ['required', 'integer', 'exists:stocks,id'],
            'returnForm.quantity'        => ['required', 'integer', 'min:1'],
            'returnForm.price'           => ['nullable', 'numeric', 'min:1'],
            'returnForm.product_size_id' => [
                Rule::requiredIf(!empty($this->returnSizeOptions)),
                'nullable',
                'integer',
                'exists:product_sizes,id',
            ],
            'returnForm.reason' => ['nullable', 'string', 'max:255'],
        ]);
    }

    protected function validateExchangeForm(): array
    {
        return $this->validate([
            'exchangeForm.in_product_id'      => ['required', 'integer', 'exists:products,id'],
            'exchangeForm.out_product_id'     => ['required', 'integer', 'exists:products,id'],
            'exchangeForm.stock_id'           => ['required', 'integer', 'exists:stocks,id'],
            'exchangeForm.quantity'           => ['required', 'integer', 'min:1'],
            'exchangeForm.in_price'           => ['nullable', 'numeric', 'min:1'],
            'exchangeForm.out_price'          => ['nullable', 'numeric', 'min:1'],
            'exchangeForm.in_product_size_id' => [
                Rule::requiredIf(!empty($this->exchangeInSizeOptions)),
                'nullable',
                'integer',
                'exists:product_sizes,id',
            ],
            'exchangeForm.out_product_size_id' => [
                Rule::requiredIf(!empty($this->exchangeOutSizeOptions)),
                'nullable',
                'integer',
                'exists:product_sizes,id',
            ],
            'exchangeForm.reason' => ['nullable', 'string', 'max:255'],
        ]);
    }

    protected function resetReturnForm(): void
    {
        $this->returnForm = [
            'product_id'      => null,
            'product_size_id' => null,
            'stock_id'        => $this->getDefaultStockId(),
            'quantity'        => 1,
            'price'           => null,
            'reason'          => null,
        ];

        $this->returnSizeOptions          = [];
        $this->returnProductSearch        = '';
        $this->returnProductOptions       = [];
        $this->returnSelectedProductLabel = null;
        $this->returnVariantLabel         = 'Razmer';
        $this->refreshSelectedProductLabels();
    }

    protected function resetExchangeForm(): void
    {
        $this->exchangeForm = [
            'in_product_id'       => null,
            'in_product_size_id'  => null,
            'out_product_id'      => null,
            'out_product_size_id' => null,
            'stock_id'            => $this->getDefaultStockId(),
            'quantity'            => 1,
            'in_price'            => null,
            'out_price'           => null,
            'reason'              => null,
        ];

        $this->exchangeInSizeOptions           = [];
        $this->exchangeOutSizeOptions          = [];
        $this->exchangePriceDifference         = null;
        $this->exchangeInProductSearch         = '';
        $this->exchangeOutProductSearch        = '';
        $this->exchangeInProductOptions        = [];
        $this->exchangeOutProductOptions       = [];
        $this->exchangeInSelectedProductLabel  = null;
        $this->exchangeOutSelectedProductLabel = null;
        $this->exchangeInVariantLabel          = 'Razmer';
        $this->exchangeOutVariantLabel         = 'Razmer';
        $this->refreshSelectedProductLabels();
    }

    protected function recalcExchangeDifference(): void
    {
        $quantity = (int) ($this->exchangeForm['quantity'] ?? 0);
        $inPrice  = (int) round($this->exchangeForm['in_price'] ?? 0);
        $outPrice = (int) round($this->exchangeForm['out_price'] ?? 0);

        if ($quantity > 0 && $inPrice > 0 && $outPrice > 0) {
            $this->exchangePriceDifference = ($outPrice - $inPrice) * $quantity;
        } else {
            $this->exchangePriceDifference = null;
        }
    }

    protected function getVariantLabelForProduct(?Product $product): string
    {
        if (!$product) {
            return 'Razmer';
        }

        return $product->isColorBased() ? 'Rang' : 'Razmer';
    }

    protected function getVariantPluralLabelForProduct(?Product $product): string
    {
        if (!$product) {
            return 'Razmerlar';
        }

        return $product->isColorBased() ? 'Ranglar' : 'Razmerlar';
    }

    protected function loadReturnProductMeta(?int $productId): void
    {
        $this->returnSizeOptions             = [];
        $this->returnForm['product_size_id'] = null;

        if (!$productId) {
            $this->returnForm['price'] = null;

            return;
        }

        $product = Product::with('sizes')->find($productId);

        if (!$product) {
            $this->returnForm['price'] = null;

            return;
        }

        $this->returnVariantLabel = $this->getVariantLabelForProduct($product);

        if ($product->isPackageBased()) {
            $this->returnSizeOptions = [];
        } else {
            $this->returnSizeOptions = $product->sizes
                ->map(fn ($size) => [
                    'id'    => $size->id,
                    'label' => $size->size,
                ])
                ->toArray();
        }

        $this->returnForm['price'] = $product->price;
    }

    protected function loadExchangeProductMeta(string $target, ?int $productId): void
    {
        $sizeProperty = $target === 'in' ? 'exchangeInSizeOptions' : 'exchangeOutSizeOptions';
        $sizeField    = "{$target}_product_size_id";
        $priceField   = "{$target}_price";

        $this->{$sizeProperty}          = [];
        $this->exchangeForm[$sizeField] = null;

        if (!$productId) {
            $this->exchangeForm[$priceField] = null;
            $this->recalcExchangeDifference();

            return;
        }

        $product = Product::with('sizes')->find($productId);

        if (!$product) {
            $this->exchangeForm[$priceField] = null;
            $this->recalcExchangeDifference();

            return;
        }

        $variantLabelProperty          = $target === 'in' ? 'exchangeInVariantLabel' : 'exchangeOutVariantLabel';
        $this->{$variantLabelProperty} = $this->getVariantLabelForProduct($product);

        if ($product->isPackageBased()) {
            $this->{$sizeProperty} = [];
        } else {
            $this->{$sizeProperty} = $product->sizes
                ->map(fn ($size) => [
                    'id'    => $size->id,
                    'label' => $size->size,
                ])
                ->toArray();
        }

        $this->exchangeForm[$priceField] = $product->price;
        $this->recalcExchangeDifference();
    }

    protected function applyFormErrors(string $prefix, array $errors): void
    {
        foreach ($errors as $field => $messages) {
            $messages = (array) $messages;

            foreach ($messages as $message) {
                $this->addError("{$prefix}.{$field}", $message);
            }
        }
    }

    protected function getDefaultStockId(): ?int
    {
        return $this->stockOptions ? (int) array_key_first($this->stockOptions) : null;
    }

    protected function refreshSelectedProductLabels(): void
    {
        $this->returnSelectedProductLabel      = $this->getProductLabel($this->returnForm['product_id'] ?? null);
        $this->exchangeInSelectedProductLabel  = $this->getProductLabel($this->exchangeForm['in_product_id'] ?? null);
        $this->exchangeOutSelectedProductLabel = $this->getProductLabel($this->exchangeForm['out_product_id'] ?? null);
    }

    protected function buildProductOptions(?string $term = null): array
    {
        $term = trim((string) ($term ?? ''));

        if ($term === '') {
            return [];
        }

        return Product::query()
            ->where(function ($q) use ($term) {
                $q->where('name', 'ilike', "%{$term}%")
                    ->orWhere('barcode', 'ilike', "%{$term}%");
            })
            ->orderBy('name')
            ->limit(25)
            ->get(['id', 'name', 'barcode'])
            ->mapWithKeys(function (Product $product) {
                return [$product->id => $this->formatProductOptionLabel($product)];
            })
            ->all();
    }

    protected function getProductLabel(?int $productId): ?string
    {
        if (!$productId) {
            return null;
        }

        $product = Product::find($productId);

        return $product ? $this->formatProductOptionLabel($product) : null;
    }

    protected function formatProductOptionLabel(Product $product): string
    {
        $label   = $product->name ?? 'Noma’lum';
        $barcode = trim((string) ($product->barcode ?? ''));

        if ($barcode !== '') {
            return "{$label} ({$barcode})";
        }

        return $label;
    }

    protected function normalizeMixedPaymentValue($value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        $amount = round(max(0, (float) $value), 2);

        return $amount > 0 ? $amount : null;
    }
}
