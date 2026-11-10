<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\HeadOfficeReportService;
use App\Services\ReportService;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function index(Request $r, ReportService $service, HeadOfficeReportService $headOfficeReports): View|StreamedResponse
    {
        if ($r->user()->isHeadOffice()) {
            $report = $headOfficeReports->build($r);
            if ($r->input('export') === 'csv') {
                return $this->exportCsv($report['columns'], $report['csv_rows'], 'reporte-concesionarios.csv');
            }
            $report['total'] = $report['rows']->count();
            if (! $r->boolean('print')) {
                $report['rows'] = new LengthAwarePaginator($report['rows']->forPage(max(1, $r->integer('page', 1)), 20), $report['total'], 20, max(1, $r->integer('page', 1)), ['path' => $r->url(), 'query' => $r->query()]);
            }

            return view('reports.head-office', $report);
        }
        $r->validate(['type' => ['nullable', Rule::in(array_keys(ReportService::TYPES))], 'from' => 'nullable|date_format:Y-m-d', 'to' => array_filter(['nullable', 'date_format:Y-m-d', $r->filled('from') ? 'after_or_equal:from' : null]), 'status' => 'nullable|in:Pendiente de pago,En proceso de pago,Pagado,Atrasado,Cancelado']);
        $report = $service->build($r);
        if ($r->input('export') === 'csv') {
            return $this->exportCsv($report['columns'], $report['rows'], 'reporte-'.$r->input('type', 'sales').'.csv');
        }

        $total = $report['rows']->count();
        if (! $r->boolean('print')) {
            $report['rows'] = new LengthAwarePaginator($report['rows']->forPage(max(1, $r->integer('page', 1)), 20), $total, 20, max(1, $r->integer('page', 1)), ['path' => $r->url(), 'query' => $r->query()]);
        }

        return view('reports', $report + ['total' => $total, 'types' => ReportService::TYPES, 'users' => $r->user()->canManageUsers() ? User::managedBy($r->user())->get() : collect()]);
    }

    /**
     * @param  array<int, string>  $columns
     * @param  iterable<array<int, int|string|null>>  $rows
     */
    private function exportCsv(array $columns, iterable $rows, string $filename): StreamedResponse
    {
        return response()->streamDownload(function () use ($columns, $rows): void {
            $file = fopen('php://output', 'w');
            fwrite($file, "\xEF\xBB\xBF");
            fputcsv($file, $columns, ',', '"', '');
            foreach ($rows as $row) {
                fputcsv($file, array_map(fn ($value) => preg_match('/^[=+@\-\t\r]/u', (string) $value) ? "'".$value : $value, $row), ',', '"', '');
            }
            fclose($file);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
