<?php

namespace App\Filament\Resources\Products\Pages;

use App\Models\Stock;
use Filament\Actions\Action;
use Filament\Schemas\Schema;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Forms\Components\FileUpload;
use Filament\Resources\Pages\ListRecords;
use App\Services\ProductExcelImportService;
use Illuminate\Validation\ValidationException;
use App\Filament\Resources\Products\ProductResource;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ListProducts extends ListRecords
{
    protected static string $resource = ProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('downloadImportExample')
                ->label('Excel namuna yuklash')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->tooltip('Namuna keyingi bo‘sh import kodidan boshlanadi. Eski tovarni yangilash uchun avvalgi kodini yozing.')
                ->authorize(fn (): bool => auth()->user()?->hasRole('super_admin') ?? false)
                ->action(function (): BinaryFileResponse {
                    $path = tempnam(sys_get_temp_dir(), 'products-example-');
                    app(ProductExcelImportService::class)->writeExample($path, auth()->user());

                    return response()->download($path, 'products-import-example.xlsx', [
                        'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    ])->deleteFileAfterSend();
                }),
            Action::make('importProducts')
                ->label('Exceldan import')
                ->icon('heroicon-o-arrow-up-tray')
                ->authorize(fn (): bool => auth()->user()?->hasRole('super_admin') ?? false)
                ->schema([
                    Select::make('stock_id')
                        ->label('Ombor')
                        ->options(fn (): array => Stock::query()->active()
                            ->whereHas('stores', fn ($query) => $query->whereKey(auth()->user()?->current_store_id))
                            ->pluck('name', 'id')->all())
                        ->helperText('Soni tanlangan omborda almashtiriladi (qo‘shilmaydi). Yangi mahsulotlar donali turda yaratiladi.')
                        ->required(),
                    FileUpload::make('file')
                        ->label('Excel fayl (.xlsx)')
                        ->acceptedFileTypes(['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])
                        ->maxSize(10240)
                        ->storeFiles(false)
                        ->helperText('id — do‘kon ichidagi doimiy import kodi. Yangi tovar uchun yangi kod, yangilash uchun avvalgi kodni yozing. Import kodi yo‘q eski tovarlar avtomatik bog‘lanmaydi: eski faylni qayta yuklashdan oldin ularni bog‘lash kerak, aks holda nusxa yaratiladi. Faqat birinchi varaq o‘qiladi. Nomi yoki narxi bo‘sh qatorlar o‘tkaziladi. Bo‘sh soni 0 deb olinadi.')
                        ->required(),
                ])
                ->action(function (array $data, Schema $schema): void {
                    try {
                        $counts = app(ProductExcelImportService::class)->import($data['file']->getRealPath(), auth()->user(), (int) $data['stock_id']);
                    } catch (ValidationException $exception) {
                        throw ValidationException::withMessages(collect($exception->errors())
                            ->mapWithKeys(fn (array $messages, string $field): array => [$schema->getStatePath() . '.' . $field => $messages])
                            ->all());
                    }

                    Notification::make()
                        ->title('Import yakunlandi')
                        ->body("Yaratildi: {$counts['created']}. Yangilandi: {$counts['updated']}. O‘tkazildi: {$counts['skipped']}.")
                        ->success()
                        ->send();

                    $this->resetTable();
                }),
            Action::make('gallery')
                ->label('Gallery')
                ->icon('heroicon-o-photo')
                ->color('gray')
                ->url(ProductResource::getUrl('gallery')),
            CreateAction::make(),

        ];
    }
}
