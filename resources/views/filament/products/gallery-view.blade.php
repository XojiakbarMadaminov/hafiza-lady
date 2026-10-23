<x-filament::page>
    <div class="space-y-6">
        {{-- Filtrlar --}}
        <div class="flex flex-col gap-4 md:flex-row md:items-end">
            <label class="w-full">
                <span class="sr-only">Qidiruv</span>
                <input type="search" wire:model.live.debounce.400ms="search" placeholder="Mahsulot nomi yoki bar kodi..." class="w-full rounded-xl border border-gray-200 bg-white/80 px-4 py-2 text-sm shadow-sm outline-none transition focus:border-primary-500 focus:ring-2 focus:ring-primary-500/20 dark:border-gray-700 dark:bg-gray-900 dark:text-white">
            </label>
            <label class="w-full md:w-72">
                <span class="sr-only">Kategoriya</span>
                <select wire:model.live="categoryId" class="w-full rounded-xl border border-gray-200 bg-white/80 px-4 py-2 text-sm shadow-sm outline-none transition focus:border-primary-500 focus:ring-2 focus:ring-primary-500/20 dark:border-gray-700 dark:bg-gray-900 dark:text-white">
                    <option value="">Barcha kategoriyalar</option>
                    @foreach ($filters as $id => $label)
                        <option value="{{ $id }}">{{ $label }}</option>
                    @endforeach
                </select>
            </label>
        </div>

        {{-- RESPONSIVE GRID --}}
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 md:gap-4 lg:grid-cols-5 xl:grid-cols-6">
            @forelse ($products as $product)
                <a
                    href="{{ route('filament.admin.resources.products.view', $product) }}"
                    class="group relative flex flex-col rounded-2xl border border-gray-200 bg-white p-3 shadow-sm transition hover:-translate-y-1 hover:border-primary-500 hover:shadow-lg dark:border-gray-700 dark:bg-gray-900 md:p-4"
                >

                    @php
                        $mediaItems = $product->getMedia('images');
                        $mediaCount = $mediaItems->count();
                    @endphp

                    <div class="mb-3 h-36 w-full overflow-hidden rounded-xl bg-gray-100 dark:bg-gray-800 sm:h-40 md:h-48">
                        @if ($mediaCount > 1)
                            {{-- KARUSEL --}}
                            <div
                                x-data="{
                                    activeSlide: 0,
                                    slidesCount: {{ $mediaCount }},
                                    next() { this.activeSlide = (this.activeSlide === this.slidesCount - 1) ? 0 : this.activeSlide + 1 },
                                    prev() { this.activeSlide = (this.activeSlide === 0) ? this.slidesCount - 1 : this.activeSlide - 1 }
                                }"
                                class="relative h-full w-full"
                            >
                                @foreach ($mediaItems as $index => $media)
                                    <img
                                        x-show="activeSlide === {{ $index }}"
                                        x-cloak
                                        x-transition:enter="transition ease-out duration-300"
                                        x-transition:enter-start="opacity-0"
                                        x-transition:enter-end="opacity-100"
                                        src="{{ $media->getUrl('optimized') }}"
                                        alt="{{ $product->name }}"
                                        class="absolute inset-0 h-full w-full object-cover cursor-zoom-in"
                                    >
                                @endforeach

                                {{-- Tugmalar --}}
                                <button
                                    @click.prevent.stop="prev()"
                                    class="absolute left-1 top-1/2 -translate-y-1/2 rounded-full bg-white/80 p-1 text-gray-800 opacity-0 shadow hover:bg-white group-hover:opacity-100 transition focus:outline-none md:left-2"
                                >
                                    <x-heroicon-m-chevron-left class="h-3 w-3 md:h-4 md:w-4" />
                                </button>

                                <button
                                    @click.prevent.stop="next()"
                                    class="absolute right-1 top-1/2 -translate-y-1/2 rounded-full bg-white/80 p-1 text-gray-800 opacity-0 shadow hover:bg-white group-hover:opacity-100 transition focus:outline-none md:right-2"
                                >
                                    <x-heroicon-m-chevron-right class="h-3 w-3 md:h-4 md:w-4" />
                                </button>

                                {{-- Kichik nuqtalar --}}
                                <div class="absolute bottom-2 left-0 right-0 flex justify-center space-x-1">
                                    @foreach ($mediaItems as $index => $media)
                                        <div
                                            class="h-1.5 w-1.5 rounded-full transition-colors duration-200"
                                            :class="activeSlide === {{ $index }} ? 'bg-white' : 'bg-white/50'"
                                        ></div>
                                    @endforeach
                                </div>
                            </div>

                        @elseif ($mediaCount === 1)
                            {{-- 1 TA RASM --}}
                            <div class="h-full w-full">
                                <img
                                    src="{{ $mediaItems[0]->getUrl('optimized') }}"
                                    alt="{{ $product->name }}"
                                    class="h-full w-full object-cover transition duration-300 group-hover:scale-105"
                                >
                            </div>
                        @else
                            {{-- RASM YO'Q --}}
                            <div class="flex h-full w-full items-center justify-center text-xs text-gray-500">
                                Rasm mavjud emas
                            </div>
                        @endif
                    </div>

                    {{-- Matn qismi --}}
                    <div class="flex flex-1 flex-col justify-between">
                        <div>
                            <div class="text-sm font-semibold text-gray-900 group-hover:text-primary-600 dark:text-white dark:group-hover:text-primary-400 line-clamp-2 md:text-base">
                                {{ $product->name }}
                                <span class="block text-sm text-gray-500 dark:text-gray-400">Kod: {{ $product->import_code }}</span>
                            </div>
                        </div>
                    </div>

                </a>
            @empty
                <div class="col-span-2 rounded-2xl border border-dashed border-gray-300 bg-white p-8 text-center text-sm text-gray-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-400 sm:col-span-3 lg:col-span-5 xl:col-span-6">
                    Mahsulot topilmadi.
                </div>
            @endforelse
        </div>

        <div>
            {{ $products->links() }}
        </div>
    </div>

</x-filament::page>
