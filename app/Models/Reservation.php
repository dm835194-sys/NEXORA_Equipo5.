<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Reservation extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['slot_at' => 'datetime', 'pickup_at' => 'datetime', 'until_at' => 'datetime', 'accepted_at' => 'datetime', 'arrived_at' => 'datetime', 'service_started_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function practice(): BelongsTo
    {
        return $this->belongsTo(Practice::class);
    }

    public function kit(): BelongsTo
    {
        return $this->belongsTo(Kit::class);
    }

    public function assets(): BelongsToMany
    {
        return $this->belongsToMany(Asset::class, 'reservation_assets');
    }

    public function loan(): HasOne
    {
        return $this->hasOne(Loan::class);
    }
}
