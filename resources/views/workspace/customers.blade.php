@extends('layouts.app')
@section('title','Resumen de clientes')
@section('subtitle','Tu cartera de clientes y concesionarios, con sus cobros y compromisos a la vista.')
@section('content')
@php($money=fn($cents)=>'$'.number_format($cents/100,2))
<div class="customer-overview-period"><span><i data-lucide="calendar-check"></i>Cobros y ventas del mes: {{ $monthStart->format('d/m/Y') }} al {{ $updatedAt->format('d/m/Y') }}</span><small>Actualizado a las {{ $updatedAt->format('H:i') }}</small></div>
<div @class(['customer-overview-grid','customer-overview-single'=>count($groups)===1])>
@foreach($groups as $kind=>$group)
<section class="customer-overview-panel card" data-customer-overview="{{ $kind }}" aria-label="Resumen de {{ mb_strtolower($group['title']) }}">
<header class="customer-overview-heading"><div class="customer-overview-identity"><span class="module-icon"><i data-lucide="{{ $kind==='person'?'users-round':'shopping-bag' }}"></i></span><div><h2>{{ $group['title'] }}</h2><p>{{ $group['active'] }} activos · {{ $group['total']-$group['active'] }} inactivos</p></div></div><a class="btn secondary" href="{{ route($group['create_route'],$group['route_parameters']) }}"><i data-lucide="plus"></i>Nuevo</a></header>
<div class="customer-overview-count"><strong data-contact-total>{{ $group['total'] }}</strong><div><span>Registros en total</span><small>{{ $group['new_this_month'] }} nuevos este mes</small></div><span class="customer-overview-orders">{{ $group['open_orders'] }} ventas con saldo</span></div>
<div class="customer-overview-metrics">
<div><span>Saldo por cobrar</span><strong data-metric="debt">{{ $money($group['debt_cents']) }}</strong><small>{{ $group['with_debt'] }} con deuda pendiente</small></div>
<div><span>Cobrado este mes</span><strong data-metric="received">{{ $money($group['received_month_cents']) }}</strong><small>Dinero recibido confirmado</small></div>
<div class="customer-overview-overdue"><span>Deuda vencida</span><strong data-metric="overdue">{{ $money($group['overdue_cents']) }}</strong><small>{{ $group['with_overdue'] }} con cuotas vencidas</small></div>
<div><span>Saldo a favor</span><strong data-metric="credit">{{ $money($group['credit_cents']) }}</strong><small>{{ $group['with_credit'] }} con crédito disponible</small></div>
<div><span>Ventas este mes</span><strong data-metric="sales">{{ $money($group['sold_month_cents']) }}</strong><small>{{ $group['sales_month'] }} ventas registradas</small></div>
<div><span>Por vencer en 7 días</span><strong data-metric="due-soon">{{ $money($group['due_soon_cents']) }}</strong><small>Desde hoy hasta {{ $weekEnd->format('d/m/Y') }}</small></div>
</div>
<div class="customer-overview-health"><div><span>Parte vencida de la deuda</span><strong>{{ $group['overdue_percent'] }}%</strong></div><div class="customer-overview-bar" role="progressbar" aria-label="Porcentaje de deuda vencida en {{ mb_strtolower($group['title']) }}" aria-valuenow="{{ $group['overdue_percent'] }}" aria-valuemin="0" aria-valuemax="100"><span style="width:{{ $group['overdue_percent'] }}%"></span></div></div>
@if($group['credit_cents']>0)
<div class="customer-overview-credit-note"><i data-lucide="wallet"></i><p><strong>@if($group['applicable_cents']>0){{ $money($group['applicable_cents']) }} de saldo a favor puede aplicarse a deudas del mismo titular.@else Hay saldo a favor disponible para próximas compras.@endif</strong><span>{{ $group['unapplied_receipts'] }} pagos con importe disponible. El crédito se mantiene separado de la deuda hasta que lo apliques.</span></p></div>
@endif
<div class="customer-overview-attention"><h3>Requieren atención</h3><span>Vencidos primero · Hasta 5 registros</span></div>
<div class="table-scroll"><table class="customer-overview-table"><thead><tr><th>{{ $kind==='person'?'Cliente':'Concesionario' }}</th><th>Deuda / Crédito</th><th>Acciones</th></tr></thead><tbody>
@forelse($group['attention'] as $account)<tr data-row-link="{{ route('clients.show',$account['client']) }}"><td><strong>{{ $account['client']->name }}</strong><small>{{ $account['overdue_cents']>0?'Vencido: '.$money($account['overdue_cents']):($account['applicable_cents']>0?'Saldo a favor por aplicar':($account['debt_cents']>0?'Deuda pendiente':'Saldo a favor disponible')) }}</small>@if($account['next_due'])<small>Cuota pendiente: {{ $account['next_due']->format('d/m/Y') }}</small>@endif</td><td><strong>{{ $money($account['debt_cents']) }}</strong><small>Crédito: {{ $money($account['credit_cents']) }}</small></td><td>@if($account['applicable_cents']>0)<a class="text-link" href="{{ route('receipts.index',['client_id'=>$account['client']->id,'state'=>'credit','from'=>'1900-01-01','to'=>$updatedAt->toDateString(),'date_mode'=>'custom']) }}">Aplicar saldo</a>@else<a class="text-link" href="{{ route('clients.show',$account['client']) }}">Ver ficha</a>@endif @can('update',$account['client'])<a class="text-link" href="{{ route('clients.edit',$account['client']) }}">Editar</a>@endcan</td></tr>
@empty<tr><td colspan="3" class="empty">Sin saldos pendientes de atención.</td></tr>@endforelse
</tbody></table></div>
<footer class="customer-overview-footer"><span>Saldos actuales de toda la cartera</span><a class="text-link" href="{{ route($group['list_route'],$group['route_parameters']) }}">Ver todos <i data-lucide="arrow-right"></i></a></footer>
</section>
@endforeach
</div>
@endsection
