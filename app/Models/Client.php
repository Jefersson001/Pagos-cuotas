<?php

namespace App\Models;

class Client extends OwnedModel
{
    public function receipts()
    {
        return $this->hasMany(Receipt::class);
    }

    public function getCreditCentsAttribute(): int
    {
        return $this->receipts->sum('available_cents');
    }

    public function orders()
    {
        return $this->hasMany(Order::class);
    }
}
