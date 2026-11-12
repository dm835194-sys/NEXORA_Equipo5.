<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Kit extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [];
    }

    public function assets(): HasMany
    {
        return $this->hasMany(Asset::class);
    }
}
