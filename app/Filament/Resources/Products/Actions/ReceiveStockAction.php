<?php

namespace App\Filament\Resources\Products\Actions;

use App\Models\Stock;
use App\Models\Product;
use Filament\Actions\Action;
use Filament\Schemas\Schema;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Grid;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ViewField;
use Filament\Notifications\Notification;
use App\Services\ProductStockEntryService;
use Illuminate\Validation\ValidationException;

class ReceiveStockAction
{
    public static function make(): Action
    {
        return Action::make('receiveStock')
            ->label('Kirim')
            ->icon('heroicon-o-plus-circle')
            ->color('success')
            ->authorize(fn (Product $record): bool => !$record->trashed() && auth()->user()->can('update', $record))
            ->modalHeading('Tovarga kirim qilish')
            ->modalDescription('Kiritilgan son mavjud qoldiqqa qo‘shiladi. Tovar nomi, barcode va narxlari o‘zgarmaydi.')
            ->modalSubmitActionLabel('Kirimni saqlash')
            ->modalWidth('2xl')
            ->schema(function (Product $record): array {
                $stocks = Stock::query()->active()->orderByDesc('is_main')->orderBy('id')->pluck('name', 'id')->all();

                return [
                    Grid::make(2)->schema([
                        TextInput::make('name')->label('Tovar nomi')->default($record->name)->disabled()->dehydrated(false),
                        TextInput::make('barcode')->label('Barcode')->default($record->barcode)->disabled()->dehydrated(false),
                    ]),
                    Select::make('stock_id')
                        ->label('Ombor')
                        ->options($stocks)
                        ->default(array_key_first($stocks))
                        ->visible(count($stocks) !== 1)
                        ->dehydratedWhenHidden()
                        ->required(),
                    TextInput::make('quantity')
                        ->label('Qo‘shiladigan son')
                        ->placeholder('Masalan: 10')
                        ->helperText('Bu umumiy qoldiq emas, faqat yangi kelgan tovar soni.')
                        ->numeric()->integer()->minValue(1)->maxValue(2147483647)
                        ->required()->autofocus()
                        ->visible($record->isPackageBased()),
                    ViewField::make('variants')
                        ->label($record->variant_plural_label)
                        ->view('filament.products.partials.stock-entry-variants')
                        ->default(fn (): array => app(ProductStockEntryService::class)->variantOptions($record))
                        ->visible(!$record->isPackageBased()),
                ];
            })
            ->action(function (Product $record, array $data, Schema $schema): void {
                try {
                    $quantity = app(ProductStockEntryService::class)->receive($record, $data);
                } catch (ValidationException $exception) {
                    throw ValidationException::withMessages(collect($exception->errors())
                        ->mapWithKeys(fn (array $messages, string $field): array => [$schema->getStatePath() . '.' . $field => $messages])
                        ->all());
                }

                Notification::make()->title('Kirim saqlandi')->body("{$record->name}: {$quantity} dona qo‘shildi.")->success()->send();
            });
    }
}
