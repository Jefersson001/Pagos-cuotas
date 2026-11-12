<?php

namespace Database\Seeders;

use App\Models\Dealership;
use App\Models\SalesEvent;
use Illuminate\Database\Seeder;

class SalesEventSeeder extends Seeder
{
    public function run(): void
    {
        foreach (Dealership::orderBy('id')->pluck('id') as $companyId) {
            SalesEvent::defaultForCompany($companyId);
        }
    }
}
