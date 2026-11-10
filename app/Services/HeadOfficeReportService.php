<?php

namespace App\Services;

use App\Models\Client;
use App\Support\Money;
use App\Support\MonthlyDateFilter;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class HeadOfficeReportService
{
    public function __construct(private HeadOfficeOverviewService $overview) {}

    /** @return array<string, mixed> */
    public function build(Request $request): array
    {
        abort_unless($request->user()->isHeadOffice(), 403);
        MonthlyDateFilter::apply($request);
        $request->validate(['concessionaire_id' => ['nullable', 'integer', Rule::exists('clients', 'id')->where('dealership_id', $request->user()->dealership_id)->where('customer_kind', 'dealership')], 'export' => 'nullable|in:csv', 'print' => 'sometimes|boolean']);
        $report = $this->overview->build($request, Carbon::parse($request->input('from')), Carbon::parse($request->input('to')), $request->filled('concessionaire_id') ? $request->integer('concessionaire_id') : null);
        $fields = ['clients', 'sales_month', 'sold_cents', 'received_cents', 'debt_cents', 'overdue_cents', 'credit_cents'];
        $totals = [];
        foreach ($fields as $field) {
            $totals[$field] = (int) $report['rows']->sum($field);
        }
        $csvRows = $report['rows']->map(fn (array $row): array => [$row['name'], $row['code'], ...array_map(fn (string $field): int|string => str_ends_with($field, '_cents') ? Money::decimal($row[$field]) : $row[$field], $fields)]);
        $csvRows->push(['Total', '', ...array_map(fn (string $field): int|string => str_ends_with($field, '_cents') ? Money::decimal($totals[$field]) : $totals[$field], $fields)]);

        return $report + ['totals' => $totals, 'from' => $request->input('from'), 'to' => $request->input('to'),
            'concessionaires' => Client::where('dealership_id', $report['company']->id)->where('customer_kind', 'dealership')->orderBy('name')->get(['id', 'name']),
            'columns' => ['Concesionario', 'RIF', 'Clientes actuales', 'Ventas del período', 'Vendido en el período', 'Cobrado en el período', 'Deuda actual', 'Deuda vencida actual', 'Saldo a favor actual'], 'csv_rows' => $csvRows];
    }
}
