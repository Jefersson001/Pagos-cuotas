<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $reportTitle }} · Cuota</title>
    <style>
        @page { size: A4 landscape; margin: 10mm; }
        * { box-sizing: border-box; }
        body { margin: 0; color: #171717; background: #fff; font: 9pt/1.35 Arial, sans-serif; }
        .print-toolbar { display: flex; justify-content: flex-end; gap: 10px; padding: 16px; background: #f5f5f5; }
        .print-toolbar a,.print-toolbar button { padding: 9px 14px; border: 1px solid #ddd; border-radius: 6px; background: #fff; color: #171717; font: inherit; text-decoration: none; cursor: pointer; }
        .print-toolbar button { background: #e91543; color: #fff; border-color: #e91543; }
        .report-paper { width: 277mm; max-width: 100%; margin: 16px auto; padding: 0 8px; }
        .print-heading { display: flex; justify-content: space-between; align-items: baseline; gap: 16px; border-bottom: 2px solid #e91543; padding-bottom: 10px; }
        h1 { margin: 0; font-size: 17pt; overflow-wrap: anywhere; }
        .print-brand { font-size: 17pt; font-weight: bold; color: #e91543; }
        .print-context { display: flex; flex-wrap: wrap; gap: 4px 18px; margin: 10px 0 6px; }
        .print-context div { min-width: 0; overflow-wrap: anywhere; }
        .print-context dt,.print-context dd { display: inline; margin: 0; }
        .print-context dt { color: #555; }
        .print-context dd { font-weight: bold; }
        .print-note { margin: 4px 0; color: #555; font-size: 8pt; overflow-wrap: anywhere; }
        table { width: 100%; table-layout: fixed; border-collapse: collapse; margin-top: 12px; font-size: 8pt; }
        th,td { padding: 6px 5px; text-align: left; vertical-align: top; border: 1px solid #ddd; white-space: normal; overflow-wrap: anywhere; word-break: normal; }
        th { background: #f3f3f3; font-weight: bold; }
        thead { display: table-header-group; }
        tfoot { display: table-row-group; font-weight: bold; }
        tr { break-inside: avoid; page-break-inside: avoid; }
        .empty { text-align: center; padding: 18px; }
        @media print {
            body { print-color-adjust: exact; -webkit-print-color-adjust: exact; }
            .print-toolbar { display: none!important; }
            .report-paper { width: 100%; max-width: none; margin: 0; padding: 0; }
            .print-heading,.print-context { break-inside: avoid; }
            .print-heading { break-after: avoid; }
        }
    </style>
</head>
<body>
<nav class="print-toolbar" aria-label="Acciones del reporte"><a href="{{ $backUrl }}">Volver al reporte</a><button type="button" onclick="window.print()">Imprimir / Guardar PDF</button></nav>
<main class="report-paper" data-print-report>
    <header class="print-heading"><h1>{{ $reportTitle }}</h1><span class="print-brand">cuota.</span></header>
    <dl class="print-context">@foreach($printFilters as $label=>$value)<div><dt>{{ $label }}:</dt> <dd>{{ $value }}</dd></div>@endforeach</dl>
    <p class="print-note">Generado el {{ $generatedAt->format('d/m/Y H:i') }} por {{ $generatedBy }} · {{ $total }} resultados · Importes en USD.</p>
    <p class="print-note">{{ $dateHint }}</p>
    <table data-report-table>
        <thead><tr>@foreach($columns as $column)<th scope="col">{{ $column }}</th>@endforeach</tr></thead>
        <tbody>@forelse($printRows as $row)<tr>@foreach($row as $cell)<td>{{ $cell }}</td>@endforeach</tr>@empty<tr><td colspan="{{ count($columns) }}" class="empty">Sin resultados para estos filtros.</td></tr>@endforelse</tbody>
        @if(isset($footerRow))<tfoot><tr>@foreach($footerRow as $cell)<td>{{ $cell }}</td>@endforeach</tr></tfoot>@endif
    </table>
</main>
<script>window.addEventListener('load',async()=>{await document.fonts.ready;window.print();});</script>
</body>
</html>