@extends('layouts.app')
@section('title','Mi compra #'.$order->id)
@section('subtitle',$order->description)
@section('actions')<a class="btn secondary" href="{{ route('portal.account') }}">Volver a mi cuenta</a>@endsection
@section('content')
@php($reported=$order->reportedSummary())

<div class="card form-card mb-6"><div class="record-card-heading"><h2>Resumen de compra</h2><x-record-status :states="['Borrador','Pendiente de pago','En proceso de pago','Atrasado','Pagado','Cancelado']" :current="$order->status" /></div><div class="detail-grid"><div><small>Total</small><strong>&#36;{{ number_format($order->total,2) }}</strong></div><div><small>Abonado</small><strong>&#36;{{ number_format($order->paid_cents/100,2) }}</strong></div><div><small>Saldo según pagos aprobados</small><strong>{{ $order->cancelled?'—':'$'.number_format($order->balance_cents/100,2) }}</strong></div><div><small>Cobros reportados pendientes</small><strong>&#36;{{ number_format($reported['reported_cents']/100,2) }}</strong></div><div><small>Restante por cobrar considerando reportes</small><strong>&#36;{{ number_format($reported['remaining_cents']/100,2) }}</strong></div></div></div>
<x-view-tabs id="mi-compra" >
<div data-tab-panel="Mis cuotas" data-tab-key="cuotas">
<section class="card table-card mb-6"><div class="card-heading"><h2>Mis compromisos de pago</h2></div><div class="table-scroll"><table><thead><tr><th>Compromiso</th><th>Vencimiento</th><th>Programado</th><th>Abonado</th><th>Pendiente</th></tr></thead><tbody>@forelse($order->installments as $installment)<tr><td>{{ $installment->label }}</td><td>{{ $installment->due_date->format('d/m/Y') }}</td><td>&#36;{{ number_format($installment->amount,2) }}</td><td>&#36;{{ number_format((\App\Support\Money::cents($installment->amount)-$installment->remaining_cents)/100,2) }}</td><td>{{ $order->cancelled?'—':'$'.number_format($installment->remaining_cents/100,2) }}@if(($reported['installments'][$installment->id]??0)>0)<small>Reportado: &#36;{{ number_format($reported['installments'][$installment->id]/100,2) }}</small><small>Por cobrar: &#36;{{ number_format(max(0,$installment->remaining_cents-$reported['installments'][$installment->id])/100,2) }}</small>@endif</td></tr>@empty<tr><td colspan="5" class="empty">Sin compromisos de pago.</td></tr>@endforelse</tbody></table></div></section>

</div>
<div data-tab-panel="Productos" data-tab-key="productos">
<section class="card table-card mb-6"><div class="card-heading"><h2>Productos de mi compra</h2></div><div class="table-scroll"><table><thead><tr><th>Producto</th><th>Cantidad</th><th>Precio</th></tr></thead><tbody>@foreach($order->items as $item)<tr><td>{{ $item->description }}</td><td>{{ $item->quantity }}</td><td>&#36;{{ number_format($item->unit_price,2) }}</td></tr>@endforeach</tbody></table></div></section>

</div>
</x-view-tabs>
@endsection
