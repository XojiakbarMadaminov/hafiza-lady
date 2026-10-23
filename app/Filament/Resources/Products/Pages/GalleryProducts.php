<?php

namespace App\Filament\Resources\Products\Pages;

use App\Models\Product;
use App\Models\Category;
use Illuminate\Support\Str;
use Filament\Actions\Action;
use Livewire\Attributes\Url;
use Livewire\WithPagination;
use Filament\Resources\Pages\Page;
use Illuminate\Database\Eloquent\Builder;
use App\Filament\Resources\Products\ProductResource;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class GalleryProducts extends Page
{
    use WithPagination;

    protected static string $resource = ProductResource::class;

    protected string $view = 'filament.products.gallery-view';

    #[Url(except: '')]
    public ?string $search = null;

    #[Url(as: 'category', except: null)]
    public int|string|null $categoryId = null;

    protected int $perPage = 12;

    protected function getViewData(): array
    {
        return [
            'products' => $this->getProducts(),
            'filters'  => $this->getFilters(),
        ];
    }

    public function getHeaderActions(): array
    {
        return [
            Action::make('list')
                ->label('List')
                ->icon('heroicon-o-list-bullet')
                ->color('gray')
                ->url(ProductResource::getUrl()),
        ];
    }

    public function getFilters(): array
    {
        return Category::query()
            ->orderBy('name')
            ->pluck('name', 'id')
            ->toArray();
    }

    public function getProducts(): LengthAwarePaginator
    {
        return $this->getProductsQuery()->paginate($this->perPage);
    }

    protected function getProductsQuery(): Builder
    {
        return Product::query()
            ->when($this->search, function (Builder $query, string $search) {
                $normalized = Str::lower($search);

                $query->where(function (Builder $subQuery) use ($normalized) {
                    $subQuery
                        ->whereRaw('LOWER(name) LIKE ?', ['%' . $normalized . '%'])
                        ->orWhereRaw('LOWER(barcode) LIKE ?', ['%' . $normalized . '%']);

                    if (($code = Product::parseCode($normalized)) !== null) {
                        $subQuery->orWhere('import_code', $code);
                    }
                });
            })
            ->when(filled($this->categoryId), function (Builder $query) {
                $query->where('category_id', $this->categoryId);
            })
            ->orderByDesc('created_at');
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingCategoryId(): void
    {
        $this->resetPage();
    }
}
