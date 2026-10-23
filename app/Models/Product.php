<?php

namespace App\Models;

use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\HasMedia;
use Illuminate\Support\Facades\Log;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;
use App\Models\Traits\HasCurrentStoreScope;
use Spatie\MediaLibrary\InteractsWithMedia;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Validation\ValidationException;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Product extends Model implements HasMedia
{
    use HasCurrentStoreScope, HasFactory, InteractsWithMedia, SoftDeletes;

    protected $table   = 'products';
    protected $guarded = [];

    public const TYPE_SIZE            = 'size';
    public const TYPE_PACKAGE         = 'package';
    public const TYPE_COLOR           = 'color';
    public const IMAGE_COLLECTION     = 'images';
    public const OPTIMIZED_CONVERSION = 'optimized';

    protected static bool $missingImageDriverLogged = false;

    protected $casts = [
        'type'        => 'string',
        'import_code' => 'integer',
    ];

    public static function parseCode(string $value): ?int
    {
        $value = trim($value);

        if ($value === '' || !ctype_digit($value)) {
            return null;
        }

        $code = filter_var(ltrim($value, '0'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => PHP_INT_MAX - 1000],
        ]);

        return $code === false ? null : $code;
    }

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            if ($this->isDirty('import_code')) {
                throw ValidationException::withMessages(['import_code' => 'Tovar kodini o‘zgartirish mumkin emas.']);
            }

            return parent::save($options);
        }

        if (!$this->store_id) {
            return parent::save($options);
        }

        return $this->getConnection()->transaction(function () use ($options): bool {
            $store    = Store::on($this->getConnectionName())->withTrashed()->lockForUpdate()->findOrFail($this->store_id);
            $products = static::on($this->getConnectionName())->withoutGlobalScope('current_store')->withTrashed()
                ->where('store_id', $this->store_id);
            $lastCode = max((int) $store->last_product_code, (int) (clone $products)->max('import_code'));

            if ($this->import_code === null) {
                if ($lastCode >= PHP_INT_MAX - 1000) {
                    throw ValidationException::withMessages(['import_code' => 'Tovar kodlari chegarasiga yetildi.']);
                }

                $this->import_code = $lastCode + 1;
            }

            Validator::make(['import_code' => $this->getAttributes()['import_code']], [
                'import_code' => ['required', 'integer', 'min:1', 'max:' . (PHP_INT_MAX - 1000)],
            ])->validate();

            if ((clone $products)->where('import_code', $this->import_code)->exists()) {
                throw ValidationException::withMessages(['import_code' => 'Bu tovar kodi do‘konda band.']);
            }

            $saved = parent::save($options);

            if ($saved) {
                $store->forceFill(['last_product_code' => max($lastCode, $this->import_code)])->save();
            }

            return $saved;
        });
    }

    public function sizes()
    {
        return $this->hasMany(ProductSize::class);
    }

    public function sizeStocks()
    {
        return $this->hasManyThrough(
            ProductSizeStock::class,
            ProductSize::class,
            'product_id',
            'product_size_id',
            'id',
            'id'
        );
    }

    public function productStocks(): HasMany
    {
        return $this->hasMany(ProductStock::class, 'product_id');
    }

    public function saleItems(): HasMany
    {
        return $this->hasMany(SaleItem::class, 'product_id');
    }

    public function purchaseItems(): HasMany
    {
        return $this->hasMany(PurchaseItem::class, 'product_id');
    }

    public function discounts(): BelongsToMany
    {
        return $this->belongsToMany(Discount::class);
    }

    public function excludedFromDiscounts(): BelongsToMany
    {
        return $this->belongsToMany(Discount::class, 'discount_excluded_product');
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function getDisplayLabelAttribute(): string
    {
        $parts = [];

        if ($this->import_code !== null) {
            $parts[] = 'Kod: ' . $this->import_code;
        }

        if (filled($this->barcode)) {
            $parts[] = $this->barcode;
        }

        $parts[] = $this->name;

        return implode(' — ', array_filter($parts));
    }

    public function getTotalQuantityAttribute(): int
    {
        // Prefer new unified product_stocks table when available
        $sum = $this->productStocks()->sum('quantity');

        if ($sum > 0) {
            return (int) $sum;
        }

        // Backward compatibility: sum over size-based legacy stocks if any
        return (int) $this->sizeStocks()->sum('quantity');
    }

    public function isSizeBased(): bool
    {
        return ($this->type ?? self::TYPE_SIZE) === self::TYPE_SIZE;
    }

    public function isColorBased(): bool
    {
        return ($this->type ?? self::TYPE_SIZE) === self::TYPE_COLOR;
    }

    public function isPackageBased(): bool
    {
        return ($this->type ?? self::TYPE_SIZE) === self::TYPE_PACKAGE;
    }

    public function getVariantLabelAttribute(): string
    {
        return $this->isColorBased() ? 'Rang' : 'Razmer';
    }

    public function getVariantPluralLabelAttribute(): string
    {
        return $this->isColorBased() ? 'Ranglar' : 'Razmerlar';
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        if (!self::canOptimizeImages()) {
            $this->logMissingImageDriver();

            return;
        }

        $this->addMediaConversion('optimized')
            ->performOnCollections('images')
            ->fit(Fit::Max, 1600, 1600) // faqat kattaroq bo'lsa kichraytiradi, upscaling yo'q
            ->format('webp')            // hajmni ancha kamaytiradi
            ->quality(85)               // vizual sifatni saqlagan holda
            ->optimize()                // spatie/image-optimizer orqali siqish
            ->nonQueued();              // darhol konvertatsiya
    }

    public static function canOptimizeImages(): bool
    {
        static $supportsOptimization = null;

        if ($supportsOptimization === null) {
            $supportsOptimization = extension_loaded('gd') || extension_loaded('imagick');
        }

        return $supportsOptimization;
    }

    public function getPrimaryImageUrl(): ?string
    {
        $media = $this->getFirstMedia(self::IMAGE_COLLECTION);

        if (!$media) {
            return null;
        }

        if ($media->hasGeneratedConversion(self::OPTIMIZED_CONVERSION)) {
            return $media->getUrl(self::OPTIMIZED_CONVERSION);
        }

        return $media->getUrl();
    }

    public function getImageUrls(): array
    {
        return $this->getMedia(self::IMAGE_COLLECTION)
            ->map(function (Media $media) {
                if ($media->hasGeneratedConversion(self::OPTIMIZED_CONVERSION)) {
                    return $media->getUrl(self::OPTIMIZED_CONVERSION);
                }

                return $media->getUrl();
            })
            ->filter()
            ->values()
            ->all();
    }

    protected function logMissingImageDriver(): void
    {
        if (self::$missingImageDriverLogged) {
            return;
        }

        Log::warning(
            'Skipping optimized product image conversions because neither the GD nor Imagick PHP extensions are loaded.'
        );

        self::$missingImageDriverLogged = true;
    }
}
