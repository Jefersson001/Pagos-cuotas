@extends('layouts.app')
@section('title','Pagos')
@section('subtitle','Dinero recibido, aplicaciones a ventas y saldo a favor de tus concesionarios.')
@section('actions')<a class="btn primary" href="{{ route('receipts.create') }}"><i data-lucide="plus"></i>Registrar pago</a>@endsection
@section('content')
<section data-live-panel>
<form class="filters live-filters card" data-live-filters data-live-dates data-date-default="month" data-timezone="{{ config('app.timezone') }}">@include('components.dealership-filter')@include('components.monthly-date-filter')
<div class="search"><i data-lucide="search"></i><input name="q" value="{{ request('q') }}" placeholder="Concesionario, documento, referencia o número de pago" aria-label="Consultar pagos"></div>
<select name="state" aria-label="Estado del pago"><option value="">Todos los pagos</option><option value="credit" @selected(request('state')==='credit')>Con saldo a favor</option><option value="partial" @selected(request('state')==='partial')>Parcialmente aplicado</option><option value="applied" @selected(request('state')==='applied')>Aplicados</option><option value="void" @selected(request('state')==='void')>Anulados</option></select>
@if(request('client_id'))<input type="hidden" name="client_id" value="{{ request('client_id') }}"><a class="text-link" href="{{ route('receipts.index') }}">Ver todos los concesionarios</a>@endif
</form>
<div class="card form-card my-6"><div class="detail-grid"><div><small>Dinero recibido confirmado</small><strong>${{ number_format($received/100,2) }}</strong></div><div><small>Aplicado a ventas</small><strong>${{ number_format(($received-$credit)/100,2) }}</strong></div><div><small>Saldo a favor sin aplicar</small><strong>${{ number_format($credit/100,2) }}</strong></div></div><p class="muted mt-4">Totales de los pagos que coinciden con las fechas, el concesionario y la búsqueda, antes del filtro de estado.</p></div>
<div class="card table-card"><div class="table-scroll"><table><thead><tr><th>Pago / Fecha</th><th>Concesionario</th><th>Recibido</th><th>Aplicado</th><th>Sin aplicar</th><th>Método / Referencia</th><th>Estado</th><th></th></tr></thead><tbody>
@forelse($receipts as $p)<tr><td><strong>REC-{{ str_pad($p->id,6,'0',STR_PAD_LEFT) }}</strong><small>{{ $p->created_at->format('d/m/Y H:i') }}</small></td><td>{{ $p->client->name }}<small>{{ $p->dealership->name }}</small></td><td>${{ number_format($p->amount,2) }}</td><td>${{ number_format($p->applied_cents/100,2) }}</td><td>${{ number_format($p->available_cents/100,2) }}</td><td>{{ $p->method->name }}<small>{{ $p->reference }}</small></td><td><span class="status {{ $p->voided_at?'red':'gray' }}">{{ $p->status }}</span></td><td><a class="text-link" href="{{ route('receipts.show',$p) }}">Ver pago</a></td></tr>
@empty<tr><td colspan="8" class="empty">No hay pagos que coincidan.</td></tr>@endforelse
</tbody></table></div><div class="pagination">{{ $receipts->links() }}</div></div>
</section>
@endsection
