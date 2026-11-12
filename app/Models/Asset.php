<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Asset extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['diagnosed_at' => 'datetime', 'value' => 'decimal:2'];
    }

    public function kit(): BelongsTo
    {
        return $this->belongsTo(Kit::class);
    }
}
