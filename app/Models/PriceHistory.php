<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PriceHistory extends Model
{
    protected $fillable = ['price', 'currency', 'recorded_at'];

    protected function casts(): array
    {
        return ['recorded_at' => 'datetime', 'price' => 'decimal:2'];
    }

    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }
}
