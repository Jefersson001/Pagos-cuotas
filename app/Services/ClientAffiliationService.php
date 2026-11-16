<?php

namespace App\Services;

use App\Models\Client;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class ClientAffiliationService
{
    /** @param Collection<int, Client> $clients
     * @return array{year: int, total: int, months: Collection<int, array{label: string, count: int, from: string, to: string}>}
     */
    public function summary(Request $request, Collection $clients): array
    {
        $request->validate(['affiliation_year' => 'nullable|integer|min:1900|max:'.today()->year]);
        $year = $request->filled('affiliation_year') ? $request->integer('affiliation_year') : today()->year;
        $affiliates = $clients->filter(fn (Client $client): bool => $client->customer_kind === 'person'
            && $client->affiliation_date !== null && $client->affiliation_date->year === $year
            && $client->affiliation_date->lte(today()));
        $counts = $affiliates->countBy(fn (Client $client): int => $client->affiliation_date->month);
        $months = collect(range(1, 12))->map(function (int $month) use ($year, $counts): array {
            $date = today()->setDate($year, $month, 1);

            return ['label' => $date->locale('es')->translatedFormat('M'), 'count' => $counts->get($month, 0),
                'from' => $date->toDateString(), 'to' => $date->endOfMonth()->toDateString()];
        });

        return ['year' => $year, 'total' => $affiliates->count(), 'months' => $months];
    }
}
