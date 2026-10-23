<x-filament::page class="bg-gray-100 dark:bg-gray-950">
    {{-- Auto-focus script --}}
    <script src="{{ asset('js/pos.js') }}?v={{ filemtime(public_path('js/pos.js')) }}"></script>


    {{-- Receipt Modal --}}
    @if($showReceipt)
        <div class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
            <div
                class="bg-white text-black dark:bg-gray-800 rounded-lg p-6 max-w-md w-full mx-4 max-h-[90vh] overflow-y-auto"
                style="max-height:90vh;"
                wire:click.stop
            >
                <div class="flex justify-between items-center mb-4">
                    <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Savat Cheki</h3>
                    <button wire:click="closeReceipt"
                            class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300">
                        <x-heroicon-o-x-mark class="w-6 h-6"/>
                    </button>
                </div>

                <div id="receipt-content" class="receipt-content">
                    @include('receipts.partials.default', [
                        'receiptData' => $receiptData,
                        'qrPath' => asset('images/taplink.png'),
                    ])
                </div>


                <div class="flex gap-3 mt-6">
                    <button wire:click="printReceipt"
                            class="flex-1 bg-blue-600 hover:bg-blue-700 text-blue py-2 px-4 rounded-lg font-medium">
                        <x-heroicon-o-printer class="w-5 h-5 inline mr-2"/>
                        Chop etish
                    </button>
                    <button wire:click="closeReceipt"
                            class="flex-1 bg-gray-600 hover:bg-gray-700 text-blue py-2 px-4 rounded-lg font-medium">
                        Yopish
                    </button>
                </div>

            </div>
        </div>
    @endif

    @php
        $posFieldLabelClass = 'block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1';
        $posSelectClass = 'w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 focus:border-primary-500 focus:ring-primary-500 dark:border-gray-700 dark:bg-gray-900 dark:text-white';
        $posTextareaClass = 'w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 focus:border-primary-500 focus:ring-primary-500 dark:border-gray-700 dark:bg-gray-900 dark:text-white';
        $posInputClass = 'fi-input block w-full rounded-lg border border-gray-300 outline outline-1 outline-gray-300 bg-white px-3 py-2 text-sm text-gray-900 shadow-sm transition focus:border-primary-500 focus:ring-2 focus:ring-primary-500/40 dark:border-gray-700 dark:outline-gray-700 dark:bg-gray-900 dark:text-white';
    @endphp

    {{-- Return Modal --}}
    @if($showReturnModal)
        <div class="fixed inset-0 bg-black/70 flex items-center justify-center z-50 px-4">
            <div class="bg-white text-gray-900 dark:bg-gray-900 dark:text-gray-100 rounded-xl w-full max-w-2xl shadow-xl overflow-hidden" wire:click.stop>
                <form wire:submit.prevent="submitReturn" class="p-6 space-y-5">
                    <div class="flex items-center justify-between">
                        <h3 class="text-lg font-semibold">Mahsulotni qaytarish</h3>
                        <button type="button" wire:click="closeReturnModal" class="text-gray-500 hover:text-gray-700 dark:hover:text-gray-300">
                            <x-heroicon-o-x-mark class="w-6 h-6"/>
                        </button>
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="sm:col-span-2 space-y-2">
                            <label class="{{ $posFieldLabelClass }}">Mahsulot</label>
                            <x-filament::input
                                type="text"
                                wire:model.live.debounce.300ms="returnProductSearch"
                                placeholder="Nom yoki shtrix-kod bo‘yicha qidiring..."
                                class="{{ $posInputClass }}"
                            />
                            @if($returnSelectedProductLabel)
                                <div class="flex items-center justify-between rounded-lg border border-primary-200 bg-primary-50 px-3 py-2 text-sm text-primary-700 dark:border-primary-500/40 dark:bg-primary-500/10 dark:text-primary-100">
                                    <span>{{ $returnSelectedProductLabel }}</span>
                                    <button type="button" wire:click="clearReturnProductSelection" class="text-xs font-semibold uppercase tracking-wide">O‘chirish</button>
                                </div>
                            @endif
                            @error('returnForm.product_id')
                            <p class="text-xs text-danger-600 mt-1">{{ $message }}</p>
                            @enderror
                            @if($returnProductSearch !== '')
                                @if(empty($returnProductOptions))
                                    <p class="text-xs text-gray-500">Natija topilmadi.</p>
                                @else
                                    <div class="rounded-lg border border-gray-200 dark:border-gray-700 divide-y divide-gray-100 dark:divide-gray-800 max-h-48 overflow-y-auto">
                                        @foreach($returnProductOptions as $id => $label)
                                            <button
                                                type="button"
                                                wire:click="selectReturnProduct({{ $id }})"
                                                class="w-full text-left px-3 py-2 text-sm hover:bg-primary-50 dark:hover:bg-primary-500/10"
                                            >
                                                {{ $label }}
                                            </button>
                                        @endforeach
                                    </div>
                                @endif
                            @endif
                        </div>

                        @if(!empty($returnSizeOptions))
                            <div class="sm:col-span-2">
                                <label class="{{ $posFieldLabelClass }}">{{ $returnVariantLabel }}</label>
                                <select wire:model="returnForm.product_size_id" class="{{ $posSelectClass }}">
                                    <option value="">{{ $returnVariantLabel }} tanlang</option>
                                    @foreach($returnSizeOptions as $option)
                                        <option value="{{ $option['id'] }}">{{ $option['label'] }}</option>
                                    @endforeach
                                </select>
                                @error('returnForm.product_size_id')
                                <p class="text-xs text-danger-600 mt-1">{{ $message }}</p>
                                @enderror
                            </div>
                        @endif

                        <div>
                            <label class="{{ $posFieldLabelClass }}">Sklad</label>
                            <select wire:model="returnForm.stock_id" class="{{ $posSelectClass }}">
                                <option value="">Sklad tanlang</option>
                                @foreach($stockOptions as $id => $label)
                                    <option value="{{ $id }}">{{ $label }}</option>
                                @endforeach
                            </select>
                            @error('returnForm.stock_id')
                            <p class="text-xs text-danger-600 mt-1">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label class="{{ $posFieldLabelClass }}">Miqdor</label>
                            <x-filament::input
                                type="number"
                                min="1"
                                wire:model.live.debounce.300ms.number="returnForm.quantity"
                                class="{{ $posInputClass }}"
                            />
                            @error('returnForm.quantity')
                            <p class="text-xs text-danger-600 mt-1">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label class="{{ $posFieldLabelClass }}">Narx (bir dona)</label>
                            <x-filament::input
                                type="number"
                                min="1"
                                step="1"
                                wire:model.live.debounce.300ms="returnForm.price"
                                class="{{ $posInputClass }}"
                            />
                            <p class="text-xs text-gray-500 mt-1">Bo‘sh qoldirsangiz, mahsulotning joriy narxi olinadi.</p>
                            @error('returnForm.price')
                            <p class="text-xs text-danger-600 mt-1">{{ $message }}</p>
                            @enderror
                        </div>
                        <div class="sm:col-span-2">
                            @php
                                $__total = 0;
                                if (!empty($returnItems)) {
                                    foreach ($returnItems as $__it) {
                                        $__q = (int) ($__it['quantity'] ?? 0);
                                        $__p = (int) round($__it['price'] ?? 0);
                                        if ($__q > 0 && $__p > 0) { $__total += $__q * $__p; }
                                    }
                                } else {
                                    $__q = (int) ($returnForm['quantity'] ?? 0);
                                    $__p = (int) round($returnForm['price'] ?? 0);
                                    if ($__q > 0 && $__p > 0) { $__total = $__q * $__p; }
                                }
                            @endphp
                            <div class="flex items-center justify-between rounded-lg border border-gray-200 dark:border-gray-700 px-3 py-2 mb-3">
                                <span class="text-sm text-gray-600 dark:text-gray-300">Jami qaytariladigan summa</span>
                                <span class="text-base font-semibold text-gray-900 dark:text-white">
                                    {{ number_format($__total, 0, '.', ' ') }} so'm
                                </span>
                            </div>
                        </div>

                        <div class="sm:col-span-2">
                            <label class="{{ $posFieldLabelClass }}">Izoh (ixtiyoriy)</label>
                            <textarea wire:model="returnForm.reason" rows="2" class="{{ $posTextareaClass }}"></textarea>
                            @error('returnForm.reason')
                            <p class="text-xs text-danger-600 mt-1">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>

                    <div class="flex justify-end gap-3 pt-2">
                        <x-filament::button color="gray" type="button" wire:click="closeReturnModal">Bekor qilish</x-filament::button>
                        <x-filament::button color="primary" type="submit">Qaytarishni saqlash</x-filament::button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    {{-- Exchange Modal --}}
    @if($showExchangeModal)
        <div class="fixed inset-0 bg-black/70 flex items-center justify-center z-50 px-4">
            <div class="bg-white text-gray-900 dark:bg-gray-900 dark:text-gray-100 rounded-xl w-full max-w-4xl shadow-xl overflow-y-auto max-h-[95vh]" wire:click.stop>
                <form wire:submit.prevent="submitExchange" class="p-6 space-y-6">
                    <div class="flex items-center justify-between">
                        <h3 class="text-lg font-semibold">Mahsulot almashtirish</h3>
                        <button type="button" wire:click="closeExchangeModal" class="text-gray-500 hover:text-gray-700 dark:hover:text-gray-300">
                            <x-heroicon-o-x-mark class="w-6 h-6"/>
                        </button>
                    </div>

                    <div class="grid gap-5 md:grid-cols-2">
                        <div class="space-y-4">
                            <h4 class="text-sm font-semibold text-gray-600 dark:text-gray-300 uppercase tracking-wide">Qaytarilayotgan mahsulot</h4>

                            <div class="space-y-2">
                                <label class="{{ $posFieldLabelClass }}">Mahsulot</label>
                                <x-filament::input
                                    type="text"
                                    wire:model.live.debounce.300ms="exchangeInProductSearch"
                                    placeholder="Nom yoki shtrix-kod bo‘yicha qidiring..."
                                    class="{{ $posInputClass }}"
                                />
                                @if($exchangeInSelectedProductLabel)
                                    <div class="flex items-center justify-between rounded-lg border border-primary-200 bg-primary-50 px-3 py-2 text-sm text-primary-700 dark:border-primary-500/40 dark:bg-primary-500/10 dark:text-primary-100">
                                        <span>{{ $exchangeInSelectedProductLabel }}</span>
                                        <button type="button" wire:click="clearExchangeInProductSelection" class="text-xs font-semibold uppercase tracking-wide">O‘chirish</button>
                                    </div>
                                @endif
                                @error('exchangeForm.in_product_id')
                                <p class="text-xs text-danger-600 mt-1">{{ $message }}</p>
                                @enderror
                                @if($exchangeInProductSearch !== '')
                                    @if(empty($exchangeInProductOptions))
                                        <p class="text-xs text-gray-500">Natija topilmadi.</p>
                                    @else
                                        <div class="rounded-lg border border-gray-200 dark:border-gray-700 divide-y divide-gray-100 dark:divide-gray-800 max-h-48 overflow-y-auto">
                                            @foreach($exchangeInProductOptions as $id => $label)
                                                <button
                                                    type="button"
                                                    wire:click="selectExchangeInProduct({{ $id }})"
                                                    class="w-full text-left px-3 py-2 text-sm hover:bg-primary-50 dark:hover:bg-primary-500/10"
                                                >
                                                    {{ $label }}
                                                </button>
                                            @endforeach
                                        </div>
                                    @endif
                                @endif
                            </div>

                            @if(!empty($exchangeInSizeOptions))
                                <div>
                                    <label class="{{ $posFieldLabelClass }}">{{ $exchangeInVariantLabel }}</label>
                                    <select wire:model="exchangeForm.in_product_size_id" class="{{ $posSelectClass }}">
                                        <option value="">{{ $exchangeInVariantLabel }} tanlang</option>
                                        @foreach($exchangeInSizeOptions as $option)
                                            <option value="{{ $option['id'] }}">{{ $option['label'] }}</option>
                                        @endforeach
                                    </select>
                                    @error('exchangeForm.in_product_size_id')
                                    <p class="text-xs text-danger-600 mt-1">{{ $message }}</p>
                                    @enderror
                                </div>
                            @endif

                            <div>
                                <label class="{{ $posFieldLabelClass }}">Narx (bir dona)</label>
                                <x-filament::input
                                    type="number"
                                    min="1"
                                    step="1"
                                    wire:model.live.debounce.300ms="exchangeForm.in_price"
                                    class="{{ $posInputClass }}"
                                />
                                @error('exchangeForm.in_price')
                                <p class="text-xs text-danger-600 mt-1">{{ $message }}</p>
                                @enderror
                            </div>
                        </div>

                        <div class="space-y-4">
                            <h4 class="text-sm font-semibold text-gray-600 dark:text-gray-300 uppercase tracking-wide">Beriladigan mahsulot</h4>

                            <div class="space-y-2">
                                <label class="{{ $posFieldLabelClass }}">Mahsulot</label>
                                <x-filament::input
                                    type="text"
                                    wire:model.live.debounce.300ms="exchangeOutProductSearch"
                                    placeholder="Nom yoki shtrix-kod bo‘yicha qidiring..."
                                    class="{{ $posInputClass }}"
                                />
                                @if($exchangeOutSelectedProductLabel)
                                    <div class="flex items-center justify-between rounded-lg border border-primary-200 bg-primary-50 px-3 py-2 text-sm text-primary-700 dark:border-primary-500/40 dark:bg-primary-500/10 dark:text-primary-100">
                                        <span>{{ $exchangeOutSelectedProductLabel }}</span>
                                        <button type="button" wire:click="clearExchangeOutProductSelection" class="text-xs font-semibold uppercase tracking-wide">O‘chirish</button>
                                    </div>
                                @endif
                                @error('exchangeForm.out_product_id')
                                <p class="text-xs text-danger-600 mt-1">{{ $message }}</p>
                                @enderror
                                @if($exchangeOutProductSearch !== '')
                                    @if(empty($exchangeOutProductOptions))
                                        <p class="text-xs text-gray-500">Natija topilmadi.</p>
                                    @else
                                        <div class="rounded-lg border border-gray-200 dark:border-gray-700 divide-y divide-gray-100 dark:divide-gray-800 max-h-48 overflow-y-auto">
                                            @foreach($exchangeOutProductOptions as $id => $label)
                                                <button
                                                    type="button"
                                                    wire:click="selectExchangeOutProduct({{ $id }})"
                                                    class="w-full text-left px-3 py-2 text-sm hover:bg-primary-50 dark:hover:bg-primary-500/10"
                                                >
                                                    {{ $label }}
                                                </button>
                                            @endforeach
                                        </div>
                                    @endif
                                @endif
                            </div>

                            @if(!empty($exchangeOutSizeOptions))
                                <div>
                                    <label class="{{ $posFieldLabelClass }}">{{ $exchangeOutVariantLabel }}</label>
                                    <select wire:model="exchangeForm.out_product_size_id" class="{{ $posSelectClass }}">
                                        <option value="">{{ $exchangeOutVariantLabel }} tanlang</option>
                                        @foreach($exchangeOutSizeOptions as $option)
                                            <option value="{{ $option['id'] }}">{{ $option['label'] }}</option>
                                        @endforeach
                                    </select>
                                    @error('exchangeForm.out_product_size_id')
                                    <p class="text-xs text-danger-600 mt-1">{{ $message }}</p>
                                    @enderror
                                </div>
                            @endif

                            <div>
                                <label class="{{ $posFieldLabelClass }}">Narx (bir dona)</label>
                                <x-filament::input
                                    type="number"
                                    min="1"
                                    step="1"
                                    wire:model.live.debounce.300ms="exchangeForm.out_price"
                                    class="{{ $posInputClass }}"
                                />
                                @error('exchangeForm.out_price')
                                <p class="text-xs text-danger-600 mt-1">{{ $message }}</p>
                                @enderror
                            </div>
                        </div>
                    </div>

                    <div class="grid gap-4 md:grid-cols-3">
                        <div>
                            <label class="{{ $posFieldLabelClass }}">Sklad</label>
                            <select wire:model="exchangeForm.stock_id" class="{{ $posSelectClass }}">
                                <option value="">Sklad tanlang</option>
                                @foreach($stockOptions as $id => $label)
                                    <option value="{{ $id }}">{{ $label }}</option>
                                @endforeach
                            </select>
                            @error('exchangeForm.stock_id')
                            <p class="text-xs text-danger-600 mt-1">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label class="{{ $posFieldLabelClass }}">Miqdor</label>
                            <x-filament::input
                                type="number"
                                min="1"
                                wire:model.live.debounce.300ms="exchangeForm.quantity"
                                class="{{ $posInputClass }}"
                            />
                            @error('exchangeForm.quantity')
                            <p class="text-xs text-danger-600 mt-1">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label class="{{ $posFieldLabelClass }}">Izoh</label>
                            <x-filament::input
                                type="text"
                                wire:model="exchangeForm.reason"
                                class="{{ $posInputClass }}"
                            />
                            @error('exchangeForm.reason')
                            <p class="text-xs text-danger-600 mt-1">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>

                    <div class="rounded-lg border border-gray-200 dark:border-gray-700 p-4 bg-gray-50 dark:bg-gray-800">
                        <div class="text-sm font-medium text-gray-600 dark:text-gray-300">Narx farqi</div>
                        @if(!is_null($exchangePriceDifference))
                            @if($exchangePriceDifference > 0)
                                <p class="mt-1 text-success-600 font-semibold">Mijoz to‘lashi kerak: {{ number_format($exchangePriceDifference, 0, '.', ' ') }} so‘m</p>
                            @elseif($exchangePriceDifference < 0)
                                <p class="mt-1 text-danger-600 font-semibold">Mijozga qaytarilishi kerak: {{ number_format(abs($exchangePriceDifference), 0, '.', ' ') }} so‘m</p>
                            @else
                                <p class="mt-1 text-gray-700 dark:text-gray-300">Narx farqi yo‘q.</p>
                            @endif
                        @else
                            <p class="mt-1 text-gray-500 text-sm">Har ikki mahsulot uchun narx va miqdorni kiriting.</p>
                        @endif
                    </div>

                    <div class="flex justify-end gap-3">
                        <x-filament::button color="gray" type="button" wire:click="closeExchangeModal">Bekor qilish</x-filament::button>
                        <x-filament::button color="primary" type="submit">Almashinuvni saqlash</x-filament::button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    {{-- Quick actions --}}
    <x-filament::card class="mb-6">
        <div class="flex flex-wrap gap-3">
            <x-filament::button color="warning" icon="heroicon-o-arrow-uturn-left" wire:click="openReturnModal">
                Mahsulotni qaytarish
            </x-filament::button>
            <x-filament::button color="info" icon="heroicon-o-arrows-right-left" wire:click="openExchangeModal">
                Mahsulot almashtirish
            </x-filament::button>
        </div>
    </x-filament::card>

    {{-- Cart Management Section --}}
    <x-filament::card class="mb-6" wire:key="cart-header-{{ $activeCartId }}-{{ $totals['qty'] }}">
        <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between mb-4">
            <h3 class="text-xl font-semibold text-gray-800 dark:text-gray-200 mb-3 sm:mb-0">Faol savatlar</h3>
            <x-filament::button wire:click="createNewCart" size="md" color="success" icon="heroicon-o-plus-circle">
                Yangi savat
            </x-filament::button>
        </div>

        @if(count($activeCarts) > 0)
            <div class="flex flex-wrap gap-2 mb-4 pb-4 border-b border-gray-200 dark:border-gray-700">
                @foreach($activeCarts as $cartId => $cartTotals)
                    <div wire:key="cart-{{ $cartId }}" class="relative group">
                        <x-filament::button
                            wire:click="switchCart({{ $cartId }})"
                            size="sm"
                            :color="$activeCartId === $cartId ? 'primary' : 'gray'"
                            :outlined="$activeCartId !== $cartId"
                            class="relative {{ count($activeCarts) > 1 ? 'pr-10' : '' }}"
                            tag="button"
                        >
                            Savat #{{ $cartId }}
                            @if($cartTotals['qty'] > 0)
                                <span
                                    class="ml-1.5 bg-danger-500 text-white text-xs rounded-full px-1.5 py-0.5 font-medium">
                                    {{ $cartTotals['qty'] }}
                                </span>
                            @endif
                        </x-filament::button>
                    </div>
                @endforeach
            </div>
        @endif

        <div class="text-sm text-gray-600 dark:text-gray-400">
            Joriy savat: <strong class="text-gray-900 dark:text-white">#{{ $activeCartId }}</strong>
            @if(isset($totals['qty']) && $totals['qty'] > 0)
                <span class="mx-1 text-gray-400 dark:text-gray-600">|</span>
                {{ $totals['qty'] }} mahsulot,
                <span class="font-semibold text-gray-800 dark:text-gray-200">{{ number_format($totals['amount'], 0, '.', ' ') }} so'm</span>
            @else
                <span class="mx-1 text-gray-400 dark:text-gray-600">|</span> Savat bo'sh
            @endif
        </div>
    </x-filament::card>

    {{-- Main content --}}
    <div class="space-y-12 lg:grid lg:grid-cols-1 lg:gap-8 lg:space-y-0">
        <div>
            {{-- Search inputs --}}
            <div class="mb-4 grid gap-4 md:grid-cols-2">
                <x-filament::input.wrapper>
                    <x-slot name="prefix">
                        <x-heroicon-o-magnifying-glass class="w-5 h-5 text-gray-400 dark:text-gray-500"/>
                    </x-slot>
                    <x-filament::input
                        name="search"
                        x-data="{
                            focusInput() {
                                this.$refs.searchInput.focus();
                            }
                        }"
                        x-ref="searchInput"
                        x-init="
                            $nextTick(() => focusInput());
                            document.addEventListener('visibilitychange', () => {
                                if (!document.hidden) {
                                    setTimeout(() => focusInput(), 100);
                                }
                            });
                        "
                        x-on:keydown.enter="$wire.addByBarcode($event.target.value); $event.target.value=''; $nextTick(() => focusInput())"
                        wire:model.live="search"
                        placeholder="Skanerlash yoki qo'lda kiriting..."
                        autofocus
                    />
                </x-filament::input.wrapper>

                <x-filament::input.wrapper>
                    <x-slot name="prefix">
                        <x-heroicon-o-hashtag class="w-5 h-5 text-gray-400 dark:text-gray-500"/>
                    </x-slot>
                    <x-filament::input
                        name="searchByCode"
                        inputmode="numeric"
                        x-on:keydown.enter="$wire.addByProductCode($event.target.value); $event.target.value=''"
                        wire:model.live="searchByCode"
                        placeholder="Tovar kodi orqali qidiring..."
                    />
                </x-filament::input.wrapper>
            </div>

            {{-- Search results --}}
            @if($products->isNotEmpty())
                <table class="w-full mt-4 text-sm">
                    <thead class="bg-gray-100 dark:bg-gray-800">
                    <tr>
                        <th class="px-2 py-1 text-left">Tovar kodi</th>
                        <th class="px-2 py-1 text-left">Nomi</th>
                        <th class="px-2 py-1 text-right">Narxi</th>
                        <th class="px-2 py-1"></th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach($products as $p)
                        <tr wire:key="item-{{ $p->id }}"
                             class="bg-white dark:bg-gray-900 hover:bg-gray-50 dark:hover:bg-gray-700 text-gray-900 dark:text-gray-200">
                            <td class="px-2 py-1 font-medium text-gray-700 dark:text-gray-300">
                                {{ $p->import_code }}
                            </td>
                            <td class="px-2 py-1">{{ $p->name }}</td>
                            <td class="px-2 py-1 text-right">{{ number_format($p->price, 2, '.', ' ') }}</td>
                            <td class="px-2 py-1 text-right">
                                @if($p->isPackageBased())
                                    <x-filament::button wire:click="add({{ $p->id }})" size="sm">
                                        Qo'shish
                                    </x-filament::button>
                                @else
                                    <x-filament::button
                                        wire:click="toggleVariantPicker({{ $p->id }})"
                                        data-testid="variant-toggle-{{ $p->id }}"
                                        aria-label="{{ $p->variant_label }}ni tanlash"
                                        aria-expanded="{{ $expandedVariantProductId === $p->id ? 'true' : 'false' }}"
                                        aria-controls="variant-picker-{{ $p->id }}"
                                        size="sm"
                                    >
                                        ...
                                    </x-filament::button>
                                @endif
                            </td>
                        </tr>
                        @if(!$p->isPackageBased() && $expandedVariantProductId === $p->id)
                            <tr wire:key="variant-picker-{{ $activeCartId }}-{{ $p->id }}">
                                <td colspan="4" class="px-2 py-3">
                                    <div id="variant-picker-{{ $p->id }}" data-testid="variant-picker-{{ $p->id }}" class="flex flex-wrap items-center gap-3 rounded-lg border border-gray-200 bg-gray-50 p-3 text-gray-900 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100">
                                        <span class="text-sm font-medium">{{ $p->variant_label }}ni tanlang:</span>
                                        @forelse($this->getVariantOptions($p->id) as $variant)
                                            <x-filament::button
                                                wire:key="variant-option-{{ $activeCartId }}-{{ $p->id }}-{{ $variant['id'] }}"
                                                wire:click="addVariant({{ $p->id }}, {{ $variant['id'] }})"
                                                wire:loading.attr="disabled"
                                                wire:target="addVariant,updateVariantQty"
                                                :color="$variant['selected'] ? 'primary' : 'gray'"
                                                :disabled="$variant['available'] <= ($cart[$p->id]['sizes'][$variant['id']] ?? 0)"
                                                size="sm"
                                                title="Omborda: {{ $variant['available'] }} dona"
                                            >
                                                {{ $variant['name'] }}
                                            </x-filament::button>
                                        @empty
                                            <span class="text-sm text-gray-500 dark:text-gray-400">Variantlar mavjud emas.</span>
                                        @endforelse
                                        <span class="text-xs text-gray-500 dark:text-gray-400">{{ $p->variant_label }} bosilishi bilan savatga qo'shiladi.</span>
                                    </div>
                                </td>
                            </tr>
                        @endif
                    @endforeach
                    </tbody>
                </table>
            @endif
        </div>

        {{-- Current Cart --}}
        <x-filament::card class="lg:sticky lg:top-6 h-fit">
            <div class="flex justify-between items-center mb-4 pb-3 border-b border-gray-200 dark:border-gray-700">
                <h2 class="text-lg font-semibold text-gray-800 dark:text-gray-200">Savat #{{ $activeCartId }}</h2>
                @if(isset($totals['qty']) && $totals['qty'] > 0)
                    <span
                        class="text-sm text-gray-600 dark:text-gray-400 bg-gray-100 dark:bg-gray-700 px-2 py-1 rounded-md">{{ $totals['qty'] }} mahsulot</span>
                @endif
            </div>

            @if(empty($cart))
                <div class="text-center py-10 text-gray-500 dark:text-gray-400">
                    <x-heroicon-o-shopping-cart class="w-16 h-16 mx-auto mb-3 text-gray-400 dark:text-gray-500"/>
                    <p>Savatda hozircha mahsulot yo'q.</p>
                    <p class="text-xs mt-1">Qidiruv maydonidan mahsulot qo'shing.</p>
                </div>
            @else
                @php
                    $cartLines = $this->cartLines;
                    $hasVariants = collect($cartLines)->contains(fn ($line) => $line['variant_id'] !== null);
                @endphp
                <div class="flow-root overflow-x-auto">
                    <table class="min-w-[720px] w-full text-sm divide-y divide-gray-200 dark:divide-gray-700">
                        <thead class="bg-gray-50 dark:bg-gray-800">
                        <tr class="text-xs uppercase tracking-wider text-gray-500 dark:text-gray-400">
                            <th scope="col" class="px-3 py-3 text-left font-medium">Nomi</th>
                            @if($hasVariants)
                                <th scope="col" class="px-3 py-3 text-center font-medium">Razmer / Rang</th>
                            @endif
                            <th scope="col" class="px-3 py-3 text-center font-medium">Miqdor</th>
                            <th scope="col" class="px-3 py-3 text-right font-medium">Sotish narxi</th>
                            <th scope="col" class="px-3 py-3 text-right font-medium">Jami</th>
                            <th scope="col" class="px-3 py-3 text-center font-medium">Amal</th>
                        </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 bg-white dark:divide-gray-700 dark:bg-gray-900">
                        @foreach($cartLines as $row)
                            @php
                                $quantityMethod = $row['variant_id'] === null ? 'updatePackageQty' : 'updateVariantQty';
                                $quantityArguments = $row['variant_id'] === null ? (string) $row['id'] : $row['id'] . ', ' . $row['variant_id'];
                                $lineKey = $activeCartId . '-' . $row['id'] . '-' . ($row['variant_id'] ?? 'package');
                            @endphp
                            <tr wire:key="cart-line-{{ $lineKey }}" data-testid="cart-line-{{ $row['id'] }}-{{ $row['variant_id'] ?? 'package' }}" class="text-gray-900 dark:text-gray-100">
                                <td class="w-full min-w-48 px-3 py-3">
                                    <div class="font-medium">{{ $row['name'] }}</div>
                                    @if(count($stockOptions) > 1)
                                        <select aria-label="{{ $row['name'] }} ombori" wire:change="updateStock({{ $row['id'] }}, $event.target.value)" class="mt-2 max-w-48 rounded-md border-gray-300 bg-white py-1 text-xs dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100">
                                            @foreach($stockOptions as $stockId => $stockName)
                                                <option value="{{ $stockId }}" @selected($row['stock_id'] == $stockId)>{{ $stockName }}</option>
                                            @endforeach
                                        </select>
                                    @endif
                                </td>
                                @if($hasVariants)
                                    <td class="px-3 py-3 text-center">
                                        @if($row['variant_id'] !== null)
                                            <span class="inline-flex min-w-10 items-center justify-center rounded-md bg-gray-100 px-3 py-1.5 font-medium dark:bg-gray-700">{{ $row['variant_name'] }}</span>
                                        @else
                                            <span class="text-gray-400">—</span>
                                        @endif
                                    </td>
                                @endif
                                <td class="px-3 py-3 text-center">
                                    <div class="flex items-center justify-center gap-1">
                                        <x-filament::button
                                            type="button"
                                            color="gray"
                                            size="sm"
                                            aria-label="Miqdorni kamaytirish"
                                            data-testid="quantity-decrease-{{ $lineKey }}"
                                            wire:click="{{ $quantityMethod }}({{ $quantityArguments }}, {{ $row['qty'] - 1 }})"
                                            :disabled="$row['variant_id'] === null && $row['qty'] <= 1"
                                            class="h-8 w-8 shrink-0"
                                        >
                                            <span class="text-lg leading-none" aria-hidden="true">−</span>
                                        </x-filament::button>
                                        <div wire:key="quantity-{{ $lineKey }}-{{ $row['qty'] }}" x-data="{ previousQuantity: {{ $row['qty'] }} }">
                                            <input type="number" aria-label="{{ $row['name'] }} {{ $row['variant_name'] }} miqdori" min="1" max="{{ $row['available'] }}" step="1" value="{{ $row['qty'] }}"
                                                x-on:change="const quantity = Number($event.target.value); if (!Number.isInteger(quantity) || quantity < 1) { $event.target.value = previousQuantity; } else { $wire.{{ $quantityMethod }}({{ $quantityArguments }}, quantity).then(result => { if (result === false) { $event.target.value = previousQuantity; } }); }"
                                                class="w-16 rounded-md border-gray-300 bg-white px-1 py-1.5 text-center text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100"/>
                                        </div>
                                        <x-filament::button
                                            type="button"
                                            color="gray"
                                            size="sm"
                                            aria-label="Miqdorni oshirish"
                                            data-testid="quantity-increase-{{ $lineKey }}"
                                            wire:click="{{ $quantityMethod }}({{ $quantityArguments }}, {{ $row['qty'] + 1 }})"
                                            :disabled="$row['qty'] >= $row['available']"
                                            class="h-8 w-8 shrink-0"
                                        >
                                            <span class="text-lg leading-none" aria-hidden="true">+</span>
                                        </x-filament::button>
                                    </div>
                                    <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">Mavjud: {{ $row['available'] }} dona</div>
                                </td>
                                <td class="px-3 py-3 text-right">
                                    <div wire:key="price-{{ $lineKey }}-{{ $row['price'] }}" x-data="{ previousPrice: {{ $row['price'] }} }">
                                        <input type="number" aria-label="{{ $row['name'] }} sotish narxi" min="1" step="any" value="{{ $row['price'] }}"
                                            x-on:change="const price = Number($event.target.value); if (!Number.isFinite(price) || price < 1) { $event.target.value = previousPrice; } else { $wire.updatePrice({{ $row['id'] }}, price).then(result => { if (result === false) { $event.target.value = previousPrice; } }); }"
                                            class="w-28 rounded-md border-gray-300 bg-white px-2 py-1.5 text-right text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100"/>
                                    </div>
                                </td>
                                <td class="whitespace-nowrap px-3 py-3 text-right font-semibold">{{ number_format($row['qty'] * $row['price'], 0, '.', ' ') }}</td>
                                <td class="px-3 py-3 text-center">
                                    <button type="button" wire:click="{{ $row['variant_id'] === null ? 'remove(' . $row['id'] . ')' : 'updateVariantQty(' . $row['id'] . ', ' . $row['variant_id'] . ', 0)' }}" title="O'chirish" aria-label="{{ $row['name'] }} {{ $row['variant_name'] }} ni o'chirish" class="rounded-md p-1.5 text-danger-600 hover:bg-danger-50 dark:text-danger-500 dark:hover:bg-danger-900/50">
                                        <x-heroicon-o-trash class="h-5 w-5"/>
                                    </button>
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>

                {{-- === Totallar va tugmalar === --}}
                <div class="border-t border-gray-200 dark:border-gray-700 mt-4 pt-4 space-y-2">

                    {{-- Totallar --}}
                    <div class="flex justify-between text-sm text-gray-600 dark:text-gray-400">
                        <span>Mahsulotlar soni:</span>
                        <span class="font-medium text-gray-800 dark:text-gray-200">{{ $totals['qty'] }} dona</span>
                    </div>
                    @if(($totals['discount_total'] ?? 0) > 0 || ($totals['customer_discount_amount'] ?? 0) > 0)
                        <div class="flex justify-between text-sm text-gray-600 dark:text-gray-400">
                            <span>Subtotal:</span>
                            <span class="font-medium text-gray-800 dark:text-gray-200">{{ number_format($totals['subtotal'] ?? 0, 0, '.', ' ') }} so'm</span>
                        </div>
                    @endif
                    @if(($totals['discount_total'] ?? 0) > 0)
                        <div class="flex justify-between text-sm text-success-700 dark:text-success-400">
                            <span>Avtomatik chegirma:</span>
                            <span class="font-medium">-{{ number_format($totals['discount_total'] ?? 0, 0, '.', ' ') }} so'm</span>
                        </div>
                    @endif
                    @if(($totals['customer_discount_amount'] ?? 0) > 0)
                        <div class="flex justify-between text-sm text-primary-700 dark:text-primary-400">
                            <span>Mijoz chegirmasi:</span>
                            <span class="font-medium">-{{ number_format($totals['customer_discount_amount'], 0, '.', ' ') }} so'm</span>
                        </div>
                    @endif
                    <div class="flex justify-between text-lg font-semibold text-gray-900 dark:text-white">
                        <span>Jami summa:</span>
                        <span>{{ number_format($totals['amount'], 0, '.', ' ') }} so'm</span>
                    </div>
                    @if($selectedClientId)
                        <div class="text-xs text-gray-600 dark:text-gray-400 mt-1">
                            Klient: <span class="font-medium text-gray-900 dark:text-gray-200">{{ collect($clients)->firstWhere('id', $selectedClientId)?->full_name ?? \App\Models\Client::find($selectedClientId)?->full_name }}</span>
                            — To'lov: <span class="font-medium text-gray-900 dark:text-gray-200">
                                {{ match($paymentType) {
                                    'cash' => 'Naqd',
                                    'card' => 'Karta',
                                    'debt' => 'Qarz',
                                    'partial' => 'Qisman',
                                    'mixed' => 'Karta + Naqd',
                                    'transfer' => "O'tkazma",
                                    'preorder' => 'Oldindan buyurtma',
                                    default => 'Tanlanmagan'
                                } }}
                            </span>
                            @if($paymentType === 'partial' && filled($partialPaymentAmount))
                                — To'langan: <span class="font-medium text-gray-900 dark:text-gray-200">{{ number_format($partialPaymentAmount, 2, '.', ' ') }} so'm</span>
                            @endif
                        </div>
                    @endif

                    {{-- Tugmalar satri --}}
                    <div class="mt-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                        {{-- Yopish --}}
                        <x-filament::button
                            wire:click="closeCart({{ $activeCartId }})"
                            size="sm"
                            color="danger"
                            class="sm:w-auto w-full order-2 sm:order-1"
                            wire:confirm="Savat #{{ $activeCartId }} ni yopishni tasdiqlaysizmi?"
                        >
                            Yopish
                        </x-filament::button>

                        {{-- 🧍 Klient tanlash --}}
                        <x-filament::button
                            color="warning"
                            size="sm"
                            class="sm:w-auto w-full order-1 sm:order-2"
                            wire:click="openClientPanel"
                        >
                            🧍 Klient tanlash
                        </x-filament::button>

                        @if($this->canSellWithoutClient)
                            <div data-testid="sale-without-client-controls" class="flex items-center gap-4 sm:w-auto w-full order-1 sm:order-2">
                                <label class="inline-flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                                    <input type="checkbox" wire:model.live="saleWithoutClient"
                                           class="rounded border-gray-300 dark:border-gray-600 text-primary-600 focus:ring-primary-500 dark:bg-gray-700">
                                    <span>Klientsiz sotuv</span>
                                </label>
                                <div class="flex items-center gap-3 text-sm">
                                    <label class="inline-flex items-center gap-1 text-gray-700 dark:text-gray-300">
                                        <input type="radio" value="cash" wire:model.live="saleWithoutClientPaymentType"
                                               @disabled(!$saleWithoutClient || $selectedClientId)
                                               class="text-primary-600 focus:ring-primary-500">
                                        <span>Naqd</span>
                                    </label>
                                    <label class="inline-flex items-center gap-1 text-gray-700 dark:text-gray-300">
                                        <input type="radio" value="card" wire:model.live="saleWithoutClientPaymentType"
                                               @disabled(!$saleWithoutClient || $selectedClientId)
                                               class="text-primary-600 focus:ring-primary-500">
                                        <span>Karta</span>
                                    </label>
                                </div>
                            </div>
                        @endif
                        {{-- Checkout --}}
                        <x-filament::button
                            wire:click="checkout"
                            color="success"
                            size="lg"
                            icon="heroicon-o-check-circle"
                            class="sm:w-auto w-full order-1 sm:order-3"
                        >
                            Savat #{{ $activeCartId }} ni yakunlash
                        </x-filament::button>
                    </div>

                </div>
            @endif
        </x-filament::card>
    </div>



    {{-- Klient tanlash inline panel --}}
    @if($showClientPanel)
        <div
            class="mt-4 rounded-lg border border-warning-200 bg-warning-50/40 p-3 shadow-sm dark:border-warning-900/40 dark:bg-warning-900/10"
            x-data
            x-init="$nextTick(() => $refs.clientSearch?.focus())"
            wire:key="client-panel-{{ $activeCartId }}"
        >
            <div
                class="w-full overflow-hidden rounded-lg border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-900"
            >
                {{-- Header --}}
                <div class="border-b border-gray-200 dark:border-gray-700 p-4">
                    <div class="flex items-center justify-between gap-3 mb-3">
                        <div>
                            <p class="text-xs font-medium uppercase tracking-wide text-warning-700 dark:text-warning-400">
                                Checkout
                            </p>
                            <h2 class="text-lg font-semibold text-gray-800 dark:text-gray-200">
                                Klient tanlash
                            </h2>
                        </div>
                        <button
                            wire:click="$set('showClientPanel', false)"
                            class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300"
                            title="Panelni yopish"
                        >
                            <x-heroicon-o-x-mark class="w-6 h-6"/>
                        </button>
                    </div>

                    <div class="grid gap-3 lg:grid-cols-3">
                        {{-- Qidiruv --}}
                        <div class="lg:col-span-2">
                            <x-filament::input.wrapper>
                                <x-slot name="prefix">
                                    <x-heroicon-o-magnifying-glass class="w-5 h-5 text-gray-400"/>
                                </x-slot>
                                <x-filament::input
                                    x-ref="clientSearch"
                                    wire:model.live.debounce.300ms="searchClient"
                                    placeholder="Ism yoki telefon raqam..."
                                />
                            </x-filament::input.wrapper>
                        </div>

                        {{-- Yangi klient qo'shish tugmasi --}}
                        <x-filament::button
                            wire:click="toggleCreateClientForm"
                            size="sm"
                            color="success"
                            icon="heroicon-o-plus"
                            class="w-full"
                        >
                            Yangi klient qo'shish
                        </x-filament::button>
                    </div>
                </div>

                {{-- Content --}}
                <div class="grid gap-4 p-4 lg:grid-cols-2 xl:grid-cols-3">
                    {{-- Yangi klient yaratish formasi --}}
                    @if($showCreateClientForm)
                        <x-filament::card>
                            <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-200 mb-4">
                                Yangi klient yaratish
                            </h3>

                            <div class="space-y-3">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                        To'liq ism *
                                    </label>
                                    <x-filament::input
                                        wire:model="newClient.full_name"
                                        placeholder="Masalan: Aliyev Ali"
                                        required
                                    />
                                    @error('newClient.full_name')
                                        <p class="mt-1 text-sm text-danger-600 dark:text-danger-400">{{ $message }}</p>
                                    @enderror
                                </div>

                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                        Telefon raqam
                                    </label>
                                    <x-filament::input
                                        type="tel"
                                        inputmode="tel"
                                        wire:model.live.debounce.300ms="newClient.phone"
                                        placeholder="+998901234567"
                                    />
                                    @error('newClient.phone')
                                        <p class="mt-1 text-sm text-danger-600 dark:text-danger-400">{{ $message }}</p>
                                    @enderror
                                </div>

                                <div class="flex gap-2 mt-4">
                                    <x-filament::button
                                        wire:click="createClient"
                                        color="success"
                                        class="flex-1"
                                    >
                                        Saqlash
                                    </x-filament::button>
                                    <x-filament::button
                                        wire:click="toggleCreateClientForm"
                                        color="gray"
                                        class="flex-1"
                                    >
                                        Bekor qilish
                                    </x-filament::button>
                                </div>
                            </div>
                        </x-filament::card>
                    @endif

                    {{-- Klientlar ro'yxati --}}
                    @if(!empty($clients) && count($clients) > 0)
                        <div class="space-y-2">
                            <h3 class="text-sm font-medium text-gray-600 dark:text-gray-400">
                                Klientlar ({{ count($clients) }})
                            </h3>

                            @foreach($clients as $client)
                                <div
                                    wire:click="selectClient({{ $client->id }})"
                                    class="p-3 rounded-lg border cursor-pointer transition-all
                                        {{ $selectedClientId === $client->id
                                            ? 'bg-primary-50 border-primary-500 dark:bg-primary-900/20 dark:border-primary-600'
                                            : 'bg-white border-gray-200 hover:border-primary-300 dark:bg-gray-800 dark:border-gray-700 dark:hover:border-primary-700'
                                        }}"
                                >
                                    <div class="flex items-center justify-between">
                                        <div class="flex-1">
                                            <p class="font-medium text-gray-900 dark:text-gray-100">
                                                {{ $client->full_name }}
                                            </p>
                                            @if($client->phone)
                                                <p class="text-sm text-gray-500 dark:text-gray-400">
                                                    {{ $client->phone }}
                                                </p>
                                            @endif
                                        </div>
                                        @if($selectedClientId === $client->id)
                                            <x-heroicon-o-check-circle class="w-6 h-6 text-primary-600 dark:text-primary-500"/>
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @else
                        <div class="text-center py-8">
                            <x-heroicon-o-user-group class="w-16 h-16 mx-auto mb-3 text-gray-400"/>
                            <p class="text-gray-500 dark:text-gray-400">
                                Klientlar topilmadi
                            </p>
                        </div>
                    @endif

                    {{-- To'lov usullari --}}
                    @if($selectedClientId)
                        <x-filament::card class="mt-0" x-data="{ selectedPayment: $wire.entangle('paymentType') }">
                            <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-200 mb-4">
                                To'lov usulini tanlang
                            </h3>

                            <div class="grid grid-cols-2 md:grid-cols-3 gap-3">
                                {{-- Naqd --}}
                                <button
                                    wire:click="selectPaymentType('cash')"
                                    x-on:click="selectedPayment = 'cash'"
                                    class="p-4 rounded-lg border-2 transition-all text-center"
                                    x-bind:class="selectedPayment === 'cash'
                                        ? 'border-success-500 bg-success-50 dark:bg-success-900/20'
                                        : 'border-gray-300 hover:border-success-400 dark:border-gray-600'"
                                >
                                    <div class="text-3xl mb-2">💵</div>
                                    <div class="font-medium text-gray-900 dark:text-gray-100">Naqd</div>
                                </button>

                                {{-- Karta --}}
                                <button
                                    wire:click="selectPaymentType('card')"
                                    x-on:click="selectedPayment = 'card'"
                                    class="p-4 rounded-lg border-2 transition-all text-center"
                                    x-bind:class="selectedPayment === 'card'
                                        ? 'border-primary-500 bg-primary-50 dark:bg-primary-900/20'
                                        : 'border-gray-300 hover:border-primary-400 dark:border-gray-600'"
                                >
                                    <div class="text-3xl mb-2">💳</div>
                                    <div class="font-medium text-gray-900 dark:text-gray-100">Karta</div>
                                </button>

                                {{-- Qarz --}}
                                <button
                                    wire:click="selectPaymentType('debt')"
                                    x-on:click="selectedPayment = 'debt'"
                                    class="p-4 rounded-lg border-2 transition-all text-center"
                                    x-bind:class="selectedPayment === 'debt'
                                        ? 'border-warning-500 bg-warning-50 dark:bg-warning-900/20'
                                        : 'border-gray-300 hover:border-warning-400 dark:border-gray-600'"
                                >
                                    <div class="text-3xl mb-2">📋</div>
                                    <div class="font-medium text-gray-900 dark:text-gray-100">Qarz</div>
                                </button>

                                {{-- Qisman --}}
                                <button
                                    wire:click="selectPaymentType('partial')"
                                    x-on:click="selectedPayment = 'partial'"
                                    class="p-4 rounded-lg border-2 transition-all text-center"
                                    x-bind:class="selectedPayment === 'partial'
                                        ? 'border-purple-500 bg-purple-50 dark:bg-purple-900/20'
                                        : 'border-gray-300 hover:border-purple-400 dark:border-gray-600'"
                                >
                                    <div class="text-3xl mb-2">🔀</div>
                                    <div class="font-medium text-gray-900 dark:text-gray-100">Qisman</div>
                                </button>

                                {{-- Naqd + Karta --}}
                                <button
                                    wire:click="selectPaymentType('mixed')"
                                    x-on:click="selectedPayment = 'mixed'"
                                    class="p-4 rounded-lg border-2 transition-all text-center"
                                    x-bind:class="selectedPayment === 'mixed'
                                        ? 'border-purple-500 bg-purple-50 dark:bg-purple-900/20'
                                        : 'border-gray-300 hover:border-purple-400 dark:border-gray-600'"
                                >
                                    <div class="text-3xl mb-2">💳+💵</div>
                                    <div class="font-medium text-gray-900 dark:text-gray-100">Karta + Naqd</div>
                                </button>

                                {{-- Oldindan buyurtma --}}
                                <button
                                    wire:click="selectPaymentType('preorder')"
                                    x-on:click="selectedPayment = 'preorder'"
                                    class="p-4 rounded-lg border-2 transition-all text-center"
                                    x-bind:class="selectedPayment === 'preorder'
                                        ? 'border-sky-500 bg-sky-50 dark:bg-sky-900/20'
                                        : 'border-gray-300 hover:border-sky-400 dark:border-gray-600'"
                                >
                                    <div class="text-3xl mb-2">⏳</div>
                                    <div class="font-medium text-gray-900 dark:text-gray-100">Oldindan buyurtma</div>
                                </button>
                            </div>

                            <div
                                class="mt-4 rounded-lg border border-gray-200 p-3 dark:border-gray-700"
                                x-data="{
                                    discountType: $wire.entangle('customerDiscountType').live,
                                    baseAmount: @js((float) ($totals['amount_before_customer_discount'] ?? $totals['amount'] ?? 0)),
                                }"
                            >
                                <h4 class="text-sm font-semibold text-gray-800 dark:text-gray-200">Mijoz chegirmasi</h4>
                                <div class="mt-3 grid gap-3 md:grid-cols-2">
                                    <div>
                                        <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">
                                            Chegirma turi
                                        </label>
                                        <div
                                            class="grid grid-cols-2 gap-1.5 rounded-xl bg-gray-100 p-1.5 ring-1 ring-gray-200 dark:bg-white/5 dark:ring-white/10"
                                            role="radiogroup"
                                            aria-label="Chegirma turi"
                                        >
                                            <label class="cursor-pointer">
                                                <input
                                                    type="radio"
                                                    value="percent"
                                                    x-model="discountType"
                                                    class="sr-only"
                                                >
                                                <span
                                                    class="flex min-h-12 items-center justify-center gap-2 rounded-lg px-3 py-2 text-sm font-semibold transition-all duration-200"
                                                    x-bind:class="discountType === 'percent'
                                                        ? 'bg-white text-primary-700 shadow-sm ring-1 ring-primary-500/30 dark:bg-primary-500/15 dark:text-primary-300 dark:ring-primary-400/40'
                                                        : 'text-gray-600 hover:bg-white/70 hover:text-gray-900 dark:text-gray-400 dark:hover:bg-white/5 dark:hover:text-gray-200'"
                                                >
                                                    <span
                                                        class="flex size-7 items-center justify-center rounded-md"
                                                        x-bind:class="discountType === 'percent'
                                                            ? 'bg-primary-100 text-primary-700 dark:bg-primary-500/20 dark:text-primary-300'
                                                            : 'bg-gray-200 text-gray-500 dark:bg-white/10 dark:text-gray-400'"
                                                    >
                                                        %
                                                    </span>
                                                    Foizda
                                                </span>
                                            </label>

                                            <label class="cursor-pointer">
                                                <input
                                                    type="radio"
                                                    value="fixed"
                                                    x-model="discountType"
                                                    class="sr-only"
                                                >
                                                <span
                                                    class="flex min-h-12 items-center justify-center gap-2 rounded-lg px-3 py-2 text-sm font-semibold transition-all duration-200"
                                                    x-bind:class="discountType === 'fixed'
                                                        ? 'bg-white text-primary-700 shadow-sm ring-1 ring-primary-500/30 dark:bg-primary-500/15 dark:text-primary-300 dark:ring-primary-400/40'
                                                        : 'text-gray-600 hover:bg-white/70 hover:text-gray-900 dark:text-gray-400 dark:hover:bg-white/5 dark:hover:text-gray-200'"
                                                >
                                                    <span
                                                        class="flex size-7 items-center justify-center rounded-md text-xs"
                                                        x-bind:class="discountType === 'fixed'
                                                            ? 'bg-primary-100 text-primary-700 dark:bg-primary-500/20 dark:text-primary-300'
                                                            : 'bg-gray-200 text-gray-500 dark:bg-white/10 dark:text-gray-400'"
                                                    >
                                                        so'm
                                                    </span>
                                                    Summada
                                                </span>
                                            </label>
                                        </div>
                                    </div>
                                    <div>
                                        <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">
                                            <span x-text="discountType === 'percent' ? 'Foiz (%)' : 'Summa (so\'m)'"></span>
                                        </label>
                                        <x-filament::input
                                            type="number"
                                            min="0"
                                            x-bind:max="discountType === 'percent' ? 100 : baseAmount"
                                            step="0.01"
                                            wire:model.live.debounce.300ms="customerDiscountValue"
                                            x-bind:placeholder="discountType === 'percent' ? 'Masalan: 10' : 'Masalan: 50000'"
                                        />
                                    </div>
                                </div>
                                @if(($totals['customer_discount_amount'] ?? 0) > 0)
                                    <div class="mt-3 flex items-center justify-between text-sm text-primary-700 dark:text-primary-400">
                                        <span>Qo'llanilgan chegirma:</span>
                                        <span class="font-semibold">-{{ number_format($totals['customer_discount_amount'], 0, '.', ' ') }} so'm</span>
                                    </div>
                                @endif
                            </div>

                            @if($paymentType === 'partial')
                                <div class="mt-4">
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                        Qisman to'lov summasi
                                    </label>
                                    <x-filament::input
                                        type="number"
                                        min="0"
                                        step="0.01"
                                        wire:model.live.debounce.300ms="partialPaymentAmount"
                                        placeholder="Masalan: 150000"
                                    />
                                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                                        Qolgan summa qarz sifatida saqlanadi.
                                    </p>
                                </div>
                            @endif

                            @if(in_array($paymentType, ['debt','partial']))
                                <div class="mt-4">
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                        Izoh (majburiy emas)
                                    </label>
                                    <textarea
                                        wire:model.live.debounce.300ms="paymentNote"
                                        rows="2"
                                        class="w-full border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-md shadow-sm py-1.5 px-2 text-sm focus:ring-primary-500 focus:border-primary-500"
                                        placeholder="Masalan: Qarzga olindi yoki qisman to'lov tafsiloti"
                                    ></textarea>
                                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                                        Bu izoh qarzdor tarixida saqlanadi.
                                    </p>
                                </div>
                            @endif

                            @if($paymentType === 'mixed')
                                <div class="mt-4 grid gap-3 md:grid-cols-2">
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                            Kartada to'lanadi
                                        </label>
                                        <x-filament::input
                                            type="number"
                                            min="0"
                                            step="0.01"
                                            wire:model.live.debounce.300ms="mixedPayment.card"
                                            placeholder="Masalan: 200000"
                                        />
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                            Naqdda to'lanadi
                                        </label>
                                        <x-filament::input
                                            type="number"
                                            min="0"
                                            step="0.01"
                                            wire:model.live.debounce.300ms="mixedPayment.cash"
                                            placeholder="Masalan: 150000"
                                        />
                                    </div>
                                </div>
                                <p class="text-xs text-gray-500 dark:text-gray-400 mt-2">
                                    Karta summasi kiritilganda qolgan qismi avtomatik naqdga taqsimlanadi.
                                </p>
                            @endif

                            {{-- Tanlangan ma'lumotlar --}}
                            <div class="mt-4 p-3 bg-gray-50 dark:bg-gray-800 rounded-lg">
                                <div class="flex items-center justify-between text-sm">
                                    <span class="text-gray-600 dark:text-gray-400">Klient:</span>
                                    <span class="font-medium text-gray-900 dark:text-gray-100">
                                        {{ collect($clients)->firstWhere('id', $selectedClientId)?->full_name ?? ($saleWithoutClient ? '-' : 'Tanlanmagan') }}
                                    </span>
                                </div>
                                <div class="flex items-center justify-between text-sm mt-2">
                                    <span class="text-gray-600 dark:text-gray-400">To'lov:</span>
                                    <span class="font-medium text-gray-900 dark:text-gray-100">
                                        {{ match($paymentType) {
                                            'cash' => '💵 Naqd',
                                            'card' => '💳 Karta',
                                            'debt' => '📋 Qarz',
                                            'partial' => '🔀 Qisman',
                                            'mixed' => '💳+💵 Karta + Naqd',
                                            'transfer' => '🏦 O‘tkazma',
                                            'preorder' => '⏳ Oldindan buyurtma',
                                            default => 'Tanlanmagan'
                                        } }}
                                    </span>
                                </div>
                                @if($paymentType === 'partial' && filled($partialPaymentAmount))
                                    <div class="flex items-center justify-between text-sm mt-2">
                                        <span class="text-gray-600 dark:text-gray-400">To'langan summa:</span>
                                        <span class="font-medium text-gray-900 dark:text-gray-100">
                                            {{ number_format($partialPaymentAmount, 2, '.', ' ') }} so'm
                                        </span>
                                    </div>
                                @endif

                                @if($paymentType === 'mixed')
                                    @php
                                        $mixedCash = $mixedPayment['cash'] ?? null;
                                        $mixedCard = $mixedPayment['card'] ?? null;
                                    @endphp
                                    @if(filled($mixedCard) || filled($mixedCash))
                                        <div class="flex flex-col gap-1 mt-2 text-sm">
                                            @if(filled($mixedCard))
                                                <div class="flex items-center justify-between">
                                                    <span class="text-gray-600 dark:text-gray-400">Karta:</span>
                                                    <span class="font-medium text-gray-900 dark:text-gray-100">
                                                        {{ number_format($mixedCard, 2, '.', ' ') }} so'm
                                                    </span>
                                                </div>
                                            @endif
                                            @if(filled($mixedCash))
                                                <div class="flex items-center justify-between">
                                                    <span class="text-gray-600 dark:text-gray-400">Naqd:</span>
                                                    <span class="font-medium text-gray-900 dark:text-gray-100">
                                                        {{ number_format($mixedCash, 2, '.', ' ') }} so'm
                                                    </span>
                                                </div>
                                            @endif
                                        </div>
                                    @endif
                                @endif
                            </div>
                        </x-filament::card>
                    @endif
                </div>
            </div>
        </div>
    @endif

    {{-- Global image zoom overlay (must be inside root) --}}
    <div x-data="{ open: false, i: 0, urls: [] }"
     x-on:open-img-zoom.window="urls = $event.detail.urls || []; i = 0; open = true"
     x-show="open"
     x-transition
     class="fixed inset-0 bg-black/70 flex items-center justify-center z-[100]"
     style="display: none;"
>
    <div class="relative max-w-5xl w-full mx-4" @click.outside="open = false">
        <button class="absolute -top-10 right-0 text-white" @click="open = false">✕</button>
        <template x-if="urls.length > 0">
            <div class="relative">
                <img :src="urls[i]" class="max-h-[80vh] w-auto mx-auto rounded" alt="zoom" />
                <button type="button" @click="i = (i - 1 + urls.length) % urls.length"
                        class="absolute left-2 top-1/2 -translate-y-1/2 bg-black/50 text-white rounded-full w-9 h-9 flex items-center justify-center hover:bg-black/70">‹</button>
                <button type="button" @click="i = (i + 1) % urls.length"
                        class="absolute right-2 top-1/2 -translate-y-1/2 bg-black/50 text-white rounded-full w-9 h-9 flex items-center justify-center hover:bg-black/70">›</button>
                <div class="mt-2 text-center text-xs text-gray-200" x-text="(i+1) + ' / ' + urls.length"></div>
            </div>
        </template>
        <template x-if="urls.length === 0">
            <div class="text-center text-gray-300 py-8">Rasm mavjud emas</div>
        </template>
    </div>
    </div>

</x-filament::page>
