<?php

namespace App\Support;

use App\Models\Dealership;
use App\Services\DealershipContext;
use Carbon\Carbon;
use Illuminate\Http\Request;

class ReportPrintContext
{
    /** @param array<string,string> $filters
     * @return array<string,mixed>
     */
    public static function make(Request $request, array $filters = []): array
    {
        $actor = $request->user();
        $companyId = DealershipContext::id($actor);
        $companyName = $actor->isAdmin()
            ? ($companyId ? (Dealership::find($companyId)?->name ?? 'Sin coincidencias') : 'Todas las empresas')
            : $actor->dealership->name;
        $context = ['Empresa' => $companyName, 'Desde' => $request->filled('from') ? Carbon::parse($request->input('from'))->format('d/m/Y') : 'Sin límite', 'Hasta' => $request->filled('to') ? Carbon::parse($request->input('to'))->format('d/m/Y') : 'Sin límite'];
        if (! $actor->isAdmin() && $actor->concessionaire_id) {
            $context['Concesionario'] = $actor->concessionaire->name;
        }

        return ['printFilters' => array_merge($context, $filters), 'generatedAt' => now(), 'generatedBy' => $actor->name,
            'backUrl' => route($request->route()->getName(), $request->except(['print', 'export', 'page']))];
    }
}
