@extends('layouts.app')
@section('title','Reportes')
@section('subtitle','Resultados consolidados por concesionario de tu organización.')
@section('actions')
<a class="btn primary" href="{{ route('reports',array_merge(request()->only(['from','to','date_mode','concessionaire_id']),['export'=>'csv'])) }}"><i data-lucide="download"></i>CSV (Excel)</a>
<a class="btn secondary" target="_blank" href="{{ route('reports',array_merge(request()->only(['from','to','date_mode','concessionaire_id']),['print'=>1])) }}"><i data-lucide="printer"></i>Imprimir / PDF</a>
@endsection
@section('content')
@php($money=fn($cents)=>'$'.number_format($cents/100,2))
<section data-live-panel>
<div class="card table-card mb-6"><form class="filters wrap live-filters" data-live-filters>
    <input type="hidden" name="date_mode" value="custom">
    <label>Concesionario<select name="concessionaire_id"><option value="">Todos los concesionarios</option>@foreach($concessionaires as $contact)<option value="{{ $contact->id }}" @selected(request('concessionaire_id')==$contact->id)>{{ $contact->name }}</option>@endforeach</select></label>
    <label>Desde<input name="from" type="date" value="{{ $from }}" required></label>
    <label>Hasta<input name="to" type="date" value="{{ $to }}" required></label>
    <button class="btn secondary">Aplicar filtros</button><a class="text-link" href="{{ route('reports',['date_mode'=>'month']) }}" data-clear-filters>Limpiar</a>
</form></div>
<div class="customer-overview-period"><span>{{ $company->name }} · Ventas y cobros del {{ \Carbon\Carbon::parse($from)->format('d/m/Y') }} al {{ \Carbon\Carbon::parse($to)->format('d/m/Y') }}</span><small>Clientes y saldos actuales al {{ $updatedAt->format('d/m/Y H:i') }}</small></div>
<div class="product-summary-kpis">
@foreach([['Concesionarios',$total],['Vendido en el período',$money($totals['sold_cents'])],['Cobrado en el período',$money($totals['received_cents'])],['Deuda actual',$money($totals['debt_cents'])]] as [$label,$value])<section class="card product-summary-kpi"><div><span>{{ $label }}</span></div><strong>{{ $value }}</strong></section>@endforeach
</div>
<section class="card table-card"><div class="card-heading"><div><h2>Consolidado por concesionario</h2><p>Las fechas filtran las ventas y cobros registrados. Los saldos incluyen toda la cartera vigente de los clientes finales de cada concesionario. Importes en USD.</p></div><span class="pill neutral">{{ $total }} resultados</span></div>
<div class="table-scroll"><table data-head-office-report><thead><tr>@foreach($columns as $column)<th>{{ $column }}</th>@endforeach</tr></thead><tbody>
@forelse($rows as $row)<tr data-concessionaire-id="{{ $row['id'] }}"><td><strong>{{ $row['name'] }}</strong><small>{{ $row['active']?'Activo':'Inactivo' }}</small></td><td>{{ $row['code']?:'—' }}</td>@foreach(['clients','sales_month','sold_cents','received_cents','debt_cents','overdue_cents','credit_cents'] as $field)<td>{{ str_ends_with($field,'_cents')?$money($row[$field]):$row[$field] }}</td>@endforeach</tr>
@empty<tr><td colspan="9" class="empty">No hay concesionarios para estos filtros.</td></tr>@endforelse
</tbody><tfoot><tr><td colspan="2"><strong>Total</strong></td>@foreach($totals as $field=>$value)<td><strong>{{ str_ends_with($field,'_cents')?$money($value):$value }}</strong></td>@endforeach</tr></tfoot></table></div>
@if(!request()->boolean('print'))<div class="pagination">{{ $rows->links() }}</div>@endif
</section></section>
@endsection
@if(request()->boolean('print'))@push('scripts')<script>window.addEventListener('load',()=>window.print());</script>@endpush @endif
