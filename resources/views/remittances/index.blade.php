@extends('layouts.app')
@section('title','Remesas a casa matriz')
@section('actions')@can('create',\App\Models\Remittance::class)<a class="btn primary" href="{{ route('remittances.create') }}">Preparar remesa</a>@endcan @endsection
@section('content')
<section data-live-panel>
<div class="card form-card mb-6"><div class="detail-grid"><div><small>Recaudado pendiente de envío · Todo el historial</small><strong>&#36;{{ number_format($heldCents/100,2) }}</strong></div><div><small>Enviado pendiente de aprobación · Todo el historial</small><strong>&#36;{{ number_format($sentCents/100,2) }}</strong></div></div></div>
<section class="card table-card">
<form class="filters live-filters" data-live-filters data-live-dates data-date-default="month" data-timezone="{{ config('app.timezone') }}">@include('components.monthly-date-filter')
<label>Concesionario<select name="concessionaire_id"><option value="">Todos los concesionarios</option>@foreach($concessionaires as $dealer)<option value="{{ $dealer->id }}" @selected(request('concessionaire_id')==$dealer->id)>{{ $dealer->name }}</option>@endforeach</select></label>
<label>Estado<select name="status"><option value="">Todos los estados</option>@foreach(['pending'=>'Pendiente de aprobación','approved'=>'Aprobada','rejected'=>'Rechazada','void'=>'Anulada'] as $key=>$label)<option value="{{ $key }}" @selected(request('status')===$key)>{{ $label }}</option>@endforeach</select></label></form>
<div class="table-scroll"><table><thead><tr><th>Remesa / Fecha de transferencia</th><th>Concesionario</th><th>Importe</th><th>Referencia</th><th>Estado</th><th></th></tr></thead><tbody>
@forelse($remittances as $remittance)<tr data-row-link="{{ route('remittances.show',$remittance) }}"><td>REM-{{ str_pad($remittance->id,6,'0',STR_PAD_LEFT) }}<small>{{ $remittance->payment_date->format('d/m/Y') }}</small></td><td>{{ $remittance->concessionaire->name }}</td><td>&#36;{{ number_format($remittance->amount,2) }}</td><td>{{ $remittance->reference }}</td><td>{{ $remittance->status_label }}</td><td><a class="text-link" href="{{ route('remittances.show',$remittance) }}">Ver remesa</a></td></tr>
@empty<tr><td colspan="6" class="empty">No hay remesas con estos filtros.</td></tr>@endforelse
</tbody></table></div><div class="pagination">{{ $remittances->links() }}</div>
</section>
</section>
@endsection