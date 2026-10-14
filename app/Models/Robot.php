<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Robot extends Model
{
    protected $fillable = [
        'name',
        'website_url',
        'mode',
        'output_formats',
        'smart_queries',
        'monitoring',
        'monitoring_interval',
    ];

    protected function casts(): array
    {
        return [
            'output_formats' => 'array',
            'monitoring' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function executions(): HasMany
    {
        return $this->hasMany(Execution::class);
    }

    public function latestExecution()
    {
        return $this->hasOne(Execution::class)->latestOfMany();
    }
}
