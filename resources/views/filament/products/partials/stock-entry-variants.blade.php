<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <div
        x-data="{
            variants: $wire.$entangle('{{ $getStatePath() }}'),
            adding: false,
            newName: '',
            error: '',
            addVariant() {
                const name = this.newName.trim();
                if (!name || name.length > 255) {
                    this.error = '1–255 belgidan iborat nom kiriting.';
                    return;
                }
                const existing = this.variants.find(variant => variant.name.trim().toLocaleLowerCase() === name.toLocaleLowerCase());
                if (existing) {
                    existing.selected = true;
                } else {
                    this.variants.push({ name, selected: true, quantity: null });
                }
                this.newName = '';
                this.error = '';
                this.adding = false;
                const index = this.variants.findIndex(variant => variant.name.trim().toLocaleLowerCase() === name.toLocaleLowerCase());
                this.$nextTick(() => this.$root.querySelectorAll('[data-variant-quantity]').item(index)?.focus());
            }
        }"
        x-id="['stock-variant', 'new-variant']"
        class="space-y-4"
        data-testid="stock-entry-variants"
    >
        <p class="text-sm text-gray-500 dark:text-gray-400">{{ $getRecord()->variant_label }}ni tanlang va faqat yangi kelgan sonini kiriting.</p>
        <div class="flex flex-wrap items-center gap-2">
            <template x-for="(variant, index) in variants" :key="index">
                <button
                    type="button"
                    x-on:click="variant.selected = !variant.selected; if (variant.selected) { $nextTick(() => document.getElementById($id('stock-variant') + '-' + index)?.focus()); }"
                    :aria-pressed="variant.selected"
                    :class="variant.selected ? 'border-primary-500 bg-primary-50 text-primary-700 ring-1 ring-primary-500 dark:bg-primary-500/20 dark:text-primary-300' : 'border-gray-200 bg-white text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700'"
                    class="inline-flex min-h-10 min-w-12 items-center justify-center gap-2 rounded-lg border px-4 py-2 text-sm font-medium transition focus-visible:outline-2 focus-visible:outline-primary-500"
                >
                    <span x-show="variant.selected" aria-hidden="true">✓</span>
                    <span x-text="variant.name"></span>
                </button>
            </template>
            <x-filament::button type="button" color="gray" outlined x-on:click="adding = !adding; if (adding) { $nextTick(() => $refs.newVariant.focus()); }" aria-label="Yangi {{ $getRecord()->variant_label }} qo‘shish" x-bind:aria-expanded="adding">
                +
            </x-filament::button>
        </div>

        <div x-show="adding" x-cloak class="space-y-2 rounded-lg border border-dashed border-gray-300 p-3 dark:border-gray-600">
            <label :for="$id('new-variant')" class="text-sm font-medium text-gray-700 dark:text-gray-200">Yangi {{ mb_strtolower($getRecord()->variant_label) }}</label>
            <div class="flex items-center gap-2">
                <input :id="$id('new-variant')" x-ref="newVariant" x-model="newName" x-on:keydown.enter.prevent="addVariant()" x-on:keydown.escape.stop.prevent="adding = false" type="text" maxlength="255" placeholder="{{ $getRecord()->isColorBased() ? 'Masalan: Jigarrang' : 'Masalan: 42 yoki XL' }}" class="min-w-0 flex-1 rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 dark:border-gray-600 dark:bg-gray-800 dark:text-white"/>
                <x-filament::button type="button" size="sm" x-on:click="addVariant()">Qo‘shish</x-filament::button>
            </div>
            <p x-show="error" x-text="error" role="alert" class="text-sm text-danger-600 dark:text-danger-400"></p>
        </div>

        <div class="space-y-2">
            <template x-for="(variant, index) in variants" :key="index">
                <div x-show="variant.selected" class="flex items-center gap-3 rounded-lg bg-gray-50 p-3 dark:bg-gray-800">
                    <label :for="$id('stock-variant') + '-' + index" class="min-w-0 flex-1 font-medium text-gray-900 dark:text-white" x-text="variant.name"></label>
                    <input :id="$id('stock-variant') + '-' + index" data-variant-quantity x-model.number="variant.quantity" :disabled="!variant.selected" :required="variant.selected" :aria-label="variant.name + ' uchun qo‘shiladigan son'" type="number" min="1" max="2147483647" step="1" inputmode="numeric" placeholder="Sonini kiriting" class="w-36 rounded-lg border border-gray-300 bg-white px-3 py-2 text-right text-sm text-gray-900 dark:border-gray-600 dark:bg-gray-900 dark:text-white"/>
                    <button type="button" x-on:click="variant.selected = false" :aria-label="variant.name + ' tanlovini bekor qilish'" class="rounded-md p-2 text-gray-400 hover:bg-gray-200 hover:text-danger-600 dark:hover:bg-gray-700">
                        <x-heroicon-o-x-mark class="h-4 w-4"/>
                    </button>
                </div>
            </template>
            <p x-show="!variants.some(variant => variant.selected)" class="rounded-lg border border-dashed border-gray-200 p-4 text-center text-sm text-gray-500 dark:border-gray-700 dark:text-gray-400">Yuqoridagi variantlardan birini tanlang yoki “+” bilan yangi variant qo‘shing.</p>
        </div>
    </div>
</x-dynamic-component>
