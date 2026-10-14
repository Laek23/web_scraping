<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Deal extends Model
{
    protected $fillable = [
        'title',
        'description',
        'product_url',
        'image_url',
        'gallery_images',
        'store',
        'product_details',
        'current_price',
        'regular_price',
        'currency',
        'discount_percent',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'current_price' => 'decimal:2',
            'regular_price' => 'decimal:2',
            'discount_percent' => 'integer',
            'product_details' => 'array',
            'gallery_images' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function bookmarks(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'deal_bookmarks')->withTimestamps();
    }

    public function votes(): HasMany
    {
        return $this->hasMany(DealVote::class);
    }

    public function comments(): HasMany
    {
        return $this->hasMany(DealComment::class);
    }

    public function priceHistories(): HasMany
    {
        return $this->hasMany(PriceHistory::class);
    }
}
