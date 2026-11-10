@extends('layouts.app')
@section('title','Mi estado de cuenta')
@section('subtitle','Consulta tus ventas, cuotas, pagos y saldo disponible.')
@section('content')
@php
$money=fn($cents)=>'$'.number_format($cents/100,2);
$valid=$orders->where('cancelled',false);
$pending=$valid->filter(fn($order)=>$order->balance_cents>0)->flatMap->installments->filter(fn($installment)=>$installment->remaining_cents>0);
@endphp
<div class="customer-overview-period"><span>{{ $client->name }} · {{ $client->dealership->name }}</span><small>{{ now()->format('d/m/Y H:i') }}</small></div>
<div class="product-summary-kpis">
@foreach([['Saldo pendiente',$money($valid->sum('balance_cents'))],['Saldo a favor',$money($receipts->sum('available_cents'))],['Total aprobado',$money($receipts->whereNull('voided_at')->where('approval_status','approved')->sum(fn($receipt)=>\App\Support\Money::cents($receipt->amount)))],['Próximo vencimiento',$pending->sortBy('due_date')->first()?->due_date->format('d/m/Y')??'Sin pendientes']] as [$label,$value])<section class="card product-summary-kpi"><div><span>{{ $label }}</span></div><strong>{{ $value }}</strong></section>@endforeach
</div>
@php($pendingMoney=$receipts->whereNull('voided_at')->where('approval_status','pending')->sum(fn($receipt)=>\App\Support\Money::cents($receipt->amount)))
@if($pendingMoney>0)<p class="notice mb-6">Tienes {{ $money($pendingMoney) }} recibidos pendientes de aprobación por casa matriz. Todavía no reducen tu deuda ni forman parte de tu saldo a favor disponible.</p>@endif
<section class="card table-card mb-6"><div class="product-summary-section-heading"><div><h2>Mis ventas</h2><p>Abre una venta para consultar su cronograma de cuotas.</p></div></div><div class="table-scroll"><table><thead><tr><th>Venta</th><th>Fecha</th><th>Total</th><th>Abonado</th><th>Pendiente</th><th>Estado</th><th></th></tr></thead><tbody>
@forelse($orders as $order)<tr data-row-link="{{ route('portal.order',$order) }}"><td>#{{ $order->id }} · {{ $order->description }}</td><td>{{ $order->created_at->format('d/m/Y') }}</td><td>{{ $money(\App\Support\Money::cents($order->total)) }}</td><td>{{ $money($order->paid_cents) }}</td><td>{{ $order->cancelled?'—':$money($order->balance_cents) }}</td><td>{{ $order->status }}</td><td><a class="text-link" href="{{ route('portal.order',$order) }}">Ver cuotas</a></td></tr>@empty<tr><td colspan="7" class="empty">Todavía no tienes ventas registradas.</td></tr>@endforelse
</tbody></table></div></section>
<section class="card table-card"><div class="product-summary-section-heading"><div><h2>Mis pagos</h2><p>Dinero recibido, aplicaciones y saldo a favor.</p></div></div><div class="table-scroll"><table><thead><tr><th>Pago</th><th>Fecha</th><th>Recibido</th><th>Aplicado</th><th>Disponible</th><th>Estado</th><th></th></tr></thead><tbody>
@forelse($receipts as $receipt)<tr data-row-link="{{ route('portal.receipt',$receipt) }}"><td>REC-{{ str_pad($receipt->id,6,'0',STR_PAD_LEFT) }}</td><td>{{ $receipt->created_at->format('d/m/Y') }}</td><td>{{ $money(\App\Support\Money::cents($receipt->amount)) }}</td><td>{{ $money($receipt->applied_cents) }}</td><td>{{ $money($receipt->available_cents) }}</td><td>{{ $receipt->status }}</td><td><a class="text-link" href="{{ route('portal.receipt',$receipt) }}">Ver pago</a></td></tr>@empty<tr><td colspan="7" class="empty">Todavía no tienes pagos registrados.</td></tr>@endforelse
</tbody></table></div></section>
@endsection
