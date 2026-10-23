<?php

namespace App\Filament\Pages;

use Throwable;
use App\Models\Stock;
use App\Models\Store;
use App\Models\Product;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use App\Models\StockTransfer;
use App\Enums\NavigationGroup;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use App\Services\StockTransferService;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Validation\ValidationException;
use Filament\Forms\Concerns\InteractsWithForms;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;

class MoveProduct extends Page implements HasForms
{
    use HasPageShield, InteractsWithForms;

    protected static string|null|\UnitEnum $navigationGroup = NavigationGroup::BaseActions;

    protected static ?string $navigationLabel = 'Tovarlarni ko‘chirish';

    protected static string|null|\BackedEnum $navigationIcon = 'heroicon-o-arrow-path';

    protected static ?string $title = 'Filiallararo tovar ko‘chirish';

    protected static ?int $navigationSort = 4;

    protected string $view = 'filament.pages.move-product';

    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill([
            'movement_mode' => StockTransfer::MODE_SELECTED,
            'from_store_id' => auth()->user()?->current_store_id,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Ko‘chirish turi')
                    ->schema([
                        ToggleButtons::make('movement_mode')
                            ->label('Qanday ko‘chirilsin?')
                            ->options([
                                StockTransfer::MODE_SELECTED => 'Tanlab ko‘chirish',
                                StockTransfer::MODE_ALL      => 'Barcha tovarlarni ko‘chirish',
                            ])
                            ->icons([
                                StockTransfer::MODE_SELECTED => 'heroicon-o-list-bullet',
                                StockTransfer::MODE_ALL      => 'heroicon-o-archive-box-arrow-down',
                            ])
                            ->default(StockTransfer::MODE_SELECTED)
                            ->inline()
                            ->live()
                            ->required(),
                    ])
                    ->columnSpanFull(),

                Section::make('Filial va omborlar')
                    ->columns(2)
                    ->schema([
                        Select::make('from_store_id')
                            ->label('Qaysi filialdan')
                            ->options(fn (): array => $this->storeOptions())
                            ->default(auth()->user()?->current_store_id)
                            ->searchable()
                            ->live()
                            ->afterStateUpdated(function (Set $set): void {
                                $set('from_stock_id', null);
                                $set('products', []);
                            })
                            ->required(),

                        Select::make('to_store_id')
                            ->label('Qaysi filialga')
                            ->options(fn (Get $get): array => collect($this->storeOptions())
                                ->except((int) $get('from_store_id'))
                                ->all())
                            ->searchable()
                            ->live()
                            ->afterStateUpdated(fn (Set $set) => $set('to_stock_id', null))
                            ->different('from_store_id')
                            ->required(),

                        Select::make('from_stock_id')
                            ->label('Qaysi ombordan')
                            ->options(fn (Get $get): array => $this->stockOptions((int) $get('from_store_id')))
                            ->searchable()
                            ->live()
                            ->afterStateUpdated(fn (Set $set) => $set('products', []))
                            ->required(),

                        Select::make('to_stock_id')
                            ->label('Qaysi omborga')
                            ->options(fn (Get $get): array => $this->stockOptions((int) $get('to_store_id')))
                            ->searchable()
                            ->different('from_stock_id')
                            ->required(),
                    ])
                    ->columnSpanFull(),

                Section::make('Ko‘chiriladigan mahsulotlar')
                    ->description('Mahsulotlar barcode bo‘yicha ajratiladi. Nomi bir xil, barcode’i boshqa mahsulotlar alohida hisoblanadi.')
                    ->visible(fn (Get $get): bool => $get('movement_mode') === StockTransfer::MODE_SELECTED)
                    ->schema([
                        Repeater::make('products')
                            ->label('Mahsulotlar')
                            ->schema([
                                Select::make('product_id')
                                    ->label('Mahsulot')
                                    ->searchable()
                                    ->live()
                                    ->getSearchResultsUsing(function (string $search, Get $get): array {
                                        $stockId = (int) $get('../../from_stock_id');
                                        $storeId = (int) $get('../../from_store_id');

                                        if (!$stockId || !$storeId) {
                                            return [];
                                        }

                                        return Product::withoutGlobalScope('current_store')
                                            ->where('store_id', $storeId)
                                            ->where(function ($query) use ($stockId): void {
                                                $query->whereHas('productStocks', fn ($stockQuery) => $stockQuery
                                                    ->where('stock_id', $stockId)
                                                    ->where('quantity', '>', 0))
                                                    ->orWhereHas('sizes.productStocks', fn ($stockQuery) => $stockQuery
                                                        ->where('stock_id', $stockId)
                                                        ->where('quantity', '>', 0));
                                            })
                                            ->where(function ($query) use ($search): void {
                                                $query->whereLike('name', "%{$search}%")
                                                    ->orWhereLike('barcode', "%{$search}%");

                                                if (($code = Product::parseCode($search)) !== null) {
                                                    $query->orWhere('import_code', $code);
                                                }
                                            })
                                            ->limit(50)
                                            ->get()
                                            ->mapWithKeys(fn (Product $product): array => [
                                                $product->id => $product->display_label,
                                            ])
                                            ->all();
                                    })
                                    ->getOptionLabelUsing(fn ($value): ?string => Product::withoutGlobalScope('current_store')
                                        ->find($value)?->display_label)
                                    ->afterStateUpdated(function (Set $set, ?string $state): void {
                                        if (!$state) {
                                            $set('sizes', []);
                                            $set('type', null);
                                            $set('package_quantity', 0);

                                            return;
                                        }

                                        $product = Product::withoutGlobalScope('current_store')
                                            ->with('sizes')
                                            ->find($state);
                                        $set('type', $product?->type ?? Product::TYPE_SIZE);
                                        $set('sizes', $product?->sizes
                                            ->map(fn ($size): array => [
                                                'size_id'   => $size->id,
                                                'size_name' => $size->size,
                                                'quantity'  => 0,
                                            ])
                                            ->all() ?? []);
                                    })
                                    ->required()
                                    ->autofocus(),

                                Hidden::make('type'),

                                TextInput::make('package_quantity')
                                    ->label('Miqdor (paket)')
                                    ->numeric()
                                    ->minValue(0)
                                    ->default(0)
                                    ->visible(fn (Get $get): bool => $get('type') === Product::TYPE_PACKAGE),

                                Repeater::make('sizes')
                                    ->label(fn (Get $get): string => $get('type') === Product::TYPE_COLOR ? 'Ranglar' : 'Razmerlar')
                                    ->schema([
                                        Hidden::make('size_id'),
                                        Hidden::make('size_name'),
                                        TextInput::make('quantity')
                                            ->columnSpanFull()
                                            ->label(fn (Get $get): string => $get('size_name') ?? 'Variant')
                                            ->numeric()
                                            ->minValue(0)
                                            ->default(0),
                                    ])
                                    ->grid(3)
                                    ->columns(3)
                                    ->default([])
                                    ->addable(false)
                                    ->deletable(false)
                                    ->reorderable(false)
                                    ->visible(fn (Get $get): bool => in_array($get('type'), [Product::TYPE_SIZE, Product::TYPE_COLOR], true)),
                            ])
                            ->grid()
                            ->minItems(1)
                            ->addActionLabel('Mahsulot qo‘shish')
                            ->columnSpanFull(),
                    ])
                    ->columnSpanFull(),

                Section::make('Diqqat')
                    ->description('Manba ombordagi barcha musbat qoldiq bir operatsiyada qabul qiluvchi filialga o‘tkaziladi.')
                    ->visible(fn (Get $get): bool => $get('movement_mode') === StockTransfer::MODE_ALL)
                    ->columnSpanFull(),
            ])
            ->columns()
            ->statePath('data');
    }

    public function submit(StockTransferService $service): void
    {
        try {
            $transfer = $service->transfer($this->form->getState());
        } catch (ValidationException $exception) {
            Notification::make()
                ->title('Ko‘chirish amalga oshmadi')
                ->body(collect($exception->errors())->flatten()->first() ?? $exception->getMessage())
                ->danger()
                ->send();

            return;
        } catch (Throwable $exception) {
            report($exception);

            Notification::make()
                ->title('Ko‘chirish amalga oshmadi')
                ->body('Kutilmagan xatolik yuz berdi. Qoldiqlar o‘zgartirilmadi.')
                ->danger()
                ->send();

            return;
        }

        Notification::make()
            ->title('Muvaffaqiyatli ko‘chirildi')
            ->body("{$transfer->items->count()} ta pozitsiya, jami {$transfer->total_quantity} dona ko‘chirildi.")
            ->success()
            ->send();

        $this->form->fill([
            'movement_mode' => StockTransfer::MODE_SELECTED,
            'from_store_id' => auth()->user()?->current_store_id,
        ]);
    }

    /** @return array<int, string> */
    private function storeOptions(): array
    {
        return Store::query()->orderBy('name')->pluck('name', 'id')->all();
    }

    /** @return array<int, string> */
    private function stockOptions(int $storeId): array
    {
        if (!$storeId) {
            return [];
        }

        return Stock::withoutGlobalScope('current_store')
            ->active()
            ->whereHas('stores', fn ($query) => $query->whereKey($storeId))
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }
}
