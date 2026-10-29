@extends('layouts.app')
@section('title',$client->name)
@section('subtitle','Información, ventas, pagos recibidos y saldo a favor del concesionario.')
@section('actions')<a class="btn secondary" href="{{ route('clients.edit',$client) }}"><i data-lucide="pencil"></i>Editar concesionario</a><a class="btn secondary" href="{{ route('receipts.create',['client_id'=>$client->id]) }}">Registrar pago</a><a class="btn primary" href="{{ route('orders.create',['client_id'=>$client->id]) }}"><i data-lucide="plus"></i>Nueva venta</a>@endsection
@section('content')
<section class="card form-card mb-6" data-concessionaire-details><h2>Ficha del concesionario</h2><div class="detail-grid">
@foreach(['business_type'=>'Tipo','name'=>'Cliente','condition'=>'Condición','started_at'=>'Fecha de inicio','legal_representative'=>'Representante legal','address'=>'Dirección','city'=>'Ciudad','state'=>'Estado','document'=>'RIF','phone'=>'Teléfono','email'=>'Correo'] as $field=>$label)<div><small>{{ $label }}</small><strong>{{ $field==='started_at' ? ($client->started_at?->format('d/m/Y') ?? '—') : ($client->$field ?: '—') }}</strong></div>@endforeach
</div></section>
<section class="card form-card mb-6"><h2>Estado de cuenta</h2><div class="detail-grid">
<div><small>Deuda de ventas</small><strong>${{ number_format($client->orders->where('cancelled',false)->sum('balance_cents')/100,2) }}</strong></div>
<div><small>Pagos recibidos confirmados</small><strong>${{ number_format($client->receipts->whereNull('voided_at')->sum(fn($p)=>\App\Support\Money::cents($p->amount))/100,2) }}</strong></div>
<div><small>Saldo a favor sin aplicar</small><strong>${{ number_format($client->credit_cents/100,2) }}</strong><a class="text-link" href="{{ route('receipts.index',['client_id'=>$client->id,'state'=>'credit']) }}">Ver pagos disponibles</a></div>
</div><p class="muted mt-4">La deuda disminuye al aplicar el saldo a una venta. El saldo a favor permanece disponible hasta que lo relaciones con sus cuotas.</p></section>
<section class="card table-card"><div class="card-heading"><h2>Historial de pedidos</h2></div>@include('orders.table',['orders'=>$client->orders])</section>
<section class="card table-card mt-6"><div class="card-heading"><h2>Historial de pagos recibidos</h2></div><div class="table-scroll"><table><thead><tr><th>Comprobante</th><th>Fecha</th><th>Recibido</th><th>Aplicado</th><th>Saldo a favor</th><th>Método</th><th>Estado</th></tr></thead><tbody>
@forelse($client->receipts->sortByDesc('created_at') as $p)<tr><td><a class="text-link" href="{{ route('receipts.show',$p) }}">REC-{{ str_pad($p->id,6,'0',STR_PAD_LEFT) }}</a></td><td>{{ $p->created_at->format('d/m/Y H:i') }}</td><td>${{ number_format($p->amount,2) }}</td><td>${{ number_format($p->applied_cents/100,2) }}</td><td>${{ number_format($p->available_cents/100,2) }}</td><td>{{ $p->method->name }}</td><td>{{ $p->status }}</td></tr>@empty<tr><td colspan="7" class="empty">Sin pagos registrados.</td></tr>@endforelse
</tbody></table></div></section>
@endsection