<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\ReportService;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\Rule;

class ReportController extends Controller
{
    public function index(Request $r, ReportService $service)
    {
        $r->validate(['type' => ['nullable', Rule::in(array_keys(ReportService::TYPES))], 'from' => 'nullable|date_format:Y-m-d', 'to' => array_filter(['nullable', 'date_format:Y-m-d', $r->filled('from') ? 'after_or_equal:from' : null]), 'status' => 'nullable|in:Pendiente de pago,En proceso de pago,Pagado,Atrasado,Cancelado']);
        $report = $service->build($r);
        if ($r->input('export') === 'csv') {
            return response()->streamDownload(function () use ($report) {
                $f = fopen('php://output', 'w');
                fwrite($f, "\xEF\xBB\xBF");
                fputcsv($f, $report['columns'], ',', '"', '');
                foreach ($report['rows'] as $row) {
                    fputcsv($f, array_map(fn ($s) => preg_match('/^[=+@\-\t\r]/u', (string) $s) ? "'".$s : $s, $row), ',', '"', '');
                }fclose($f);
            }, 'reporte-'.$r->input('type', 'sales').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
        }

        $total = $report['rows']->count();
        if (! $r->boolean('print')) {
            $report['rows'] = new LengthAwarePaginator($report['rows']->forPage(max(1, $r->integer('page', 1)), 20), $total, 20, max(1, $r->integer('page', 1)), ['path' => $r->url(), 'query' => $r->query()]);
        }

        return view('reports', $report + ['total' => $total, 'types' => ReportService::TYPES, 'users' => $r->user()->canManageUsers() ? User::managedBy($r->user())->get() : collect()]);
    }
}
