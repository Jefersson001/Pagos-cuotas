<?php

namespace App\Models;

class Client extends OwnedModel
{
    protected function casts(): array
    {
        return ['active' => 'boolean', 'list_number' => 'integer', 'refrigerators' => 'integer', 'refrigerators_assigned_at' => 'date', 'listed_started_at' => 'date', 'registry_date' => 'date', 'started_at' => 'date'];
    }

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
