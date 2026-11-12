@extends('layouts.app')
@php
$readOnlyPortfolio=$readOnlyPortfolio??false;
$portfolioFilters=request()->only("concessionaire_id","dealership_id");
$periodFilters=array_merge(request()->only("from","to","date_mode"),$portfolioFilters);
$money=fn($cents)=>'$'.number_format($cents/100,2);
$allDates=array_merge(['from'=>'1900-01-01','to'=>$updatedAt->toDateString(),'date_mode'=>'custom'],$portfolioFilters);
$allCredit=array_merge($allDates,['state'=>'credit']);
@endphp
@section('title','Resumen de ventas')
@section('subtitle','Ventas, pagos y cobranza conectados en un solo lugar.')
@section('actions')
@if($readOnlyPortfolio)<span class="pill neutral">Solo consulta</span><a class="btn secondary" href="{{ route('head-office.orders.index',$periodFilters) }}">Ver ventas</a>@else
<a class="btn secondary" href="{{ route('orders.create') }}"><i data-lucide="plus"></i>Nueva venta</a>
<a class="btn primary" href="{{ route('receipts.create') }}"><i data-lucide="wallet"></i>Registrar pago</a>
@endif
@endsection
@section('content')
<section data-live-panel>
<x-overview-filters :concessionaires="$concessionaires" :can-filter-concessionaires="$canFilterConcessionaires" />

@if(!$readOnlyPortfolio&&($overdueCents>0 || $dueSoonCents>0))<nav class="portfolio-shortcuts module-alerts" aria-label="Compromisos que requieren atención">@if($overdueCents>0)<a href="{{ route('collections',['filter'=>'overdue']) }}"><i data-lucide="alarm-clock"></i>{{ $overdueCount }} compromisos vencidos · {{ $money($overdueCents) }}</a>@endif @if($dueSoonCents>0)<a href="{{ route('collections',['filter'=>'pending','from'=>today()->toDateString(),'to'=>$updatedAt->copy()->startOfDay()->addDays(6)->toDateString()]) }}"><i data-lucide="calendar-clock"></i>Vencen en 7 días · {{ $money($dueSoonCents) }}</a>@endif</nav>@endif
<nav class="portfolio-shortcuts module-alerts" aria-label="Control de pagos"><a href="{{ route('receipts.index',array_merge($allDates,['state'=>'pending'])) }}">Pendientes de aprobación: {{ $money($pendingApprovalCents) }} · Todo el historial</a><span>{{ $approvedPeriodCount }} pagos aprobados en el período</span></nav>
<div class="customer-overview-period"><span><i data-lucide="calendar-days"></i>Movimientos del {{ $monthStart->format('d/m/Y') }} al {{ $periodEnd->format('d/m/Y') }} · Saldos actuales de toda la cartera</span><small>Actualizado a las {{ $updatedAt->format('H:i') }}</small></div>
<div class="product-summary-kpis" data-sales-overview>
@foreach([
['shopping-bag','Vendido en el período',$money($soldMonthCents),$salesMonth.' '.($salesMonth===1?'venta confirmada':'ventas confirmadas'),'sold'],
['wallet','Dinero recibido en el período',$money($receivedMonthCents),$receiptsMonth.' '.($receiptsMonth===1?'pago confirmado':'pagos confirmados'),'received'],
['calendar-clock','Saldo por cobrar',$money($debtCents),$openOrders.' '.($openOrders===1?'venta pendiente':'ventas pendientes').' · '.$debtClients.' '.($debtClients===1?'titular':'titulares'),'debt'],
['badge-dollar-sign','Saldo a favor disponible',$money($creditCents),$creditClients.' '.($creditClients===1?'titular con crédito':'titulares con crédito'),'credit']
] as [$icon,$label,$value,$detail,$key])
<section class="card product-summary-kpi"><div><span>{{ $label }}</span><i data-lucide="{{ $icon }}"></i></div><strong data-sales-metric="{{ $key }}">{{ $value }}</strong><small>{{ $detail }}</small></section>
@endforeach
</div>
<section class="card sales-overview-comparison">
<div class="product-summary-section-heading"><div><h2>{{ $isCustomPeriod ? 'Comparación con el período anterior' : 'Comparación con el mes anterior' }}</h2><p>{{ $monthStart->format('d/m/Y') }}–{{ $periodEnd->format('d/m/Y') }} frente a {{ $previousStart->format('d/m/Y') }}–{{ $previousEnd->format('d/m/Y') }}. {{ $isCustomPeriod ? 'Comparación con el tramo inmediatamente anterior de igual cantidad de días.' : 'Corte a las '.$updatedAt->format('H:i').' en ambos períodos.' }}</p></div><i data-lucide="trending-up"></i></div>
<div class="sales-overview-comparison-grid">
@foreach($comparison as $key=>$item)<div data-sales-comparison="{{ $key }}"><div><h3>{{ $item['label'] }}</h3><span class="sales-overview-change">{{ $item['change_percent']===null?'Sin base anterior':($item['change_percent']==0?'Sin variación':($item['change_percent']>0?'+':'').number_format($item['change_percent'],1).'%') }}</span></div><div class="sales-overview-comparison-values"><div><span>Período elegido</span><strong>{{ $money($item['current_cents']) }}</strong></div><div><span>Período anterior</span><strong>{{ $money($item['previous_cents']) }}</strong></div></div><p>{{ $item['difference_cents']===0?'Mismo importe':($item['difference_cents']>0?'Aumento de ':'Disminución de ').$money(abs($item['difference_cents'])) }}</p></div>@endforeach
</div>
</section>
<div class="product-summary-layout sales-overview-layout">
<div class="product-summary-main">
<section class="card">
<div class="product-summary-section-heading"><div><h2>Ventas y cobros del período</h2><p>Lo vendido y el dinero nuevo recibido, agrupados por tramo del período.</p></div><i data-lucide="chart-no-axes-combined"></i></div>
<div class="sales-overview-legend"><span><b></b>Ventas confirmadas</span><span><b></b>Dinero recibido</span></div>
<div class="sales-overview-weeks">
@foreach($weeks as $week)<div class="sales-overview-week"><span>{{ $week['label'] }}</span><div><div class="sales-overview-track"><span style="width:{{ round($week['sold_cents']/$maxWeekCents*100,2) }}%"></span></div><div class="sales-overview-track sales-overview-cash"><span style="width:{{ round($week['received_cents']/$maxWeekCents*100,2) }}%"></span></div></div><div><strong>{{ $money($week['sold_cents']) }}</strong><strong>{{ $money($week['received_cents']) }}</strong></div></div>@endforeach
</div>
<div class="sales-overview-applications"><div><span>Aplicado a ventas en el período</span><strong data-sales-metric="applied">{{ $money($appliedMonthCents) }}</strong></div><div><span>De pagos anteriores al período</span><strong data-sales-metric="old-credit">{{ $money($oldCreditAppliedCents) }}</strong></div></div>
<p class="product-summary-footnote">Vender genera una deuda; recibir un pago registra dinero; aplicarlo reduce la deuda. Las aplicaciones no se suman como un nuevo ingreso. Cobros por fecha de pago; registros antiguos sin fecha usan la de registro. Excluye ventas canceladas y pagos anulados.</p>
</section>
<section class="card sales-overview-due">
<div class="product-summary-section-heading"><div><h2>Compromisos de pago</h2><p>Vencidas primero y próximas hasta el {{ $weekEnd->format('d/m/Y') }}. Mostrando hasta 6.</p></div><i data-lucide="alarm-clock"></i></div>
<div class="sales-overview-due-totals"><div><span>Deuda vencida</span><strong data-sales-metric="overdue">{{ $money($overdueCents) }}</strong><small>{{ $overdueCount }} {{ $overdueCount===1?'compromiso pendiente':'compromisos pendientes' }}</small></div><div><span>Vence en los próximos 7 días</span><strong data-sales-metric="due-soon">{{ $money($dueSoonCents) }}</strong><small>{{ $dueSoonCount }} {{ $dueSoonCount===1?'compromiso':'compromisos' }} · Incluye hoy</small></div></div>
<div class="table-scroll"><table><thead><tr><th>Cliente / Venta</th><th>Compromiso</th><th>Vencimiento</th><th>Pendiente</th><th>Acciones</th></tr></thead><tbody>
@forelse($dueItems as $row)
@php($installment=$row['installment'])
<tr data-row-link="{{ route($readOnlyPortfolio?'head-office.orders.show':'orders.show',$row['order']) }}"><td><strong>{{ $row['order']->client->name }}</strong><small>Venta #{{ $row['order']->id }}</small></td><td>{{ $installment->label }}</td><td>{{ $installment->due_date->format('d/m/Y') }}<small>{{ $installment->due_date->lt($updatedAt->copy()->startOfDay())?'Vencido':($installment->due_date->isSameDay($updatedAt)?'Vence hoy':'Próximo') }}</small></td><td><strong>{{ $money($installment->remaining_cents) }}</strong></td><td><a class="text-link" href="{{ route($readOnlyPortfolio?'head-office.orders.show':'orders.show',$row['order']) }}">{{ $readOnlyPortfolio?'Ver detalle':'Gestionar' }}</a> @can('update',$row['order'])<a class="text-link" href="{{ route('orders.edit',$row['order']) }}">Editar</a>@endcan</td></tr>
@empty<tr><td colspan="5" class="empty">No hay compromisos vencidos ni vencimientos en los próximos 7 días.</td></tr>@endforelse
</tbody></table></div>
<div class="customer-overview-footer"><span>Incluye iniciales y cuotas parcialmente pagadas.</span><a class="text-link" href="{{ route($readOnlyPortfolio?'head-office.orders.index':'collections',$portfolioFilters) }}">{{ $readOnlyPortfolio?'Ver ventas':'Ver cobranzas' }}</a></div>
</section>
<section class="card sales-overview-debtors">
<div class="product-summary-section-heading"><div><h2>Principales deudores</h2><p>Hasta 5 titulares. Mayor deuda vencida primero; después, saldo total.</p></div><i data-lucide="users-round"></i></div>
<div class="table-scroll"><table><thead><tr><th>Titular</th><th>Deuda total</th><th>Vencido</th><th>Mayor atraso</th><th>Acciones</th></tr></thead><tbody>
@forelse($topDebtors as $account)<tr data-row-link="{{ route($readOnlyPortfolio?'head-office.clients.show':'clients.show',$account['client']) }}"><td><strong>{{ $account['client']->name }}</strong><small>{{ $readOnlyPortfolio?$account['client']->concessionaire->name:($account['client']->customer_kind==='dealership'?'Concesionario':'Cliente') }}</small></td><td>{{ $money($account['debt_cents']) }}</td><td><strong>{{ $money($account['overdue_cents']) }}</strong></td><td>{{ $account['days_overdue']>0?$account['days_overdue'].' '.($account['days_overdue']===1?'día':'días'):'Al día' }}</td><td><a class="text-link" href="{{ route($readOnlyPortfolio?'head-office.clients.show':'clients.show',$account['client']) }}">Ver estado</a> @can('update',$account['client'])<a class="text-link" href="{{ route('clients.edit',$account['client']) }}">Editar</a>@endcan</td></tr>
@empty<tr><td colspan="5" class="empty">No hay clientes ni concesionarios con deuda pendiente.</td></tr>@endforelse
</tbody></table></div>
<p class="product-summary-footnote">Se muestra la deuda pendiente de todas las ventas vigentes. El saldo a favor se aplica por separado.</p>
</section>
<section class="card sales-overview-payments">
<div class="product-summary-section-heading"><div><h2>Últimos pagos del período</h2><p>Hasta 5 pagos confirmados y su aplicación actual.</p></div><a class="text-link" href="{{ route('receipts.index',array_merge($periodFilters,['state'=>'approved','date_field'=>'payment'])) }}">Ver pagos</a></div>
<div class="table-scroll"><table><thead><tr><th>Pago / Cliente</th><th>Recibido</th><th>Sin aplicar</th><th>Estado</th><th>Acciones</th></tr></thead><tbody>
@forelse($recentReceipts as $receipt)<tr data-row-link="{{ route($readOnlyPortfolio?'head-office.receipts.show':'receipts.show',$receipt) }}"><td><strong>{{ $receipt->client->name }}</strong><small>REC-{{ str_pad($receipt->id,6,'0',STR_PAD_LEFT) }} · {{ ($receipt->payment_date ?? $receipt->created_at)->format('d/m/Y') }}</small></td><td>{{ $money(\App\Support\Money::cents($receipt->amount)) }}</td><td>{{ $money($receipt->available_cents) }}</td><td><span class="status gray">{{ $receipt->status }}</span></td><td><a class="text-link" href="{{ route($readOnlyPortfolio?'head-office.receipts.show':'receipts.show',$receipt) }}">Ver pago</a> @can('update',$receipt)<a class="text-link" href="{{ route('receipts.edit',$receipt) }}">Editar</a>@endcan</td></tr>
@empty<tr><td colspan="5" class="empty">Todavía no hay pagos confirmados en el período.</td></tr>@endforelse
</tbody></table></div>
</section>
</div>
<aside class="product-summary-side">
<section class="card sales-overview-aging">
<div class="product-summary-section-heading"><div><h2>Antigüedad de la deuda</h2><p>Saldo vencido pendiente, según los días de atraso de cada inicial o cuota.</p></div><i data-lucide="calendar-clock"></i></div>
<div class="sales-overview-aging-list">
@foreach($aging as $bucket)<div><div><strong>{{ $bucket['label'] }}</strong><b>{{ $money($bucket['amount_cents']) }}</b></div><div class="product-summary-type-track"><span style="width:{{ $bucket['percent'] }}%"></span></div><small>{{ $bucket['count'] }} {{ $bucket['count']===1?'compromiso pendiente':'compromisos pendientes' }} · {{ number_format($bucket['percent'],1) }}% del vencido</small></div>@endforeach
</div>@unless($readOnlyPortfolio)<a class="product-summary-catalog-link" href="{{ route('collections',['filter'=>'overdue']) }}">Ver cuotas vencidas<i data-lucide="arrow-right"></i></a>@endunless
</section>
<section class="card">
<div class="product-summary-section-heading"><div><h2>Estado de la cartera</h2><p>Ventas de todo el historial y su estado actual.</p></div><i data-lucide="clipboard-list"></i></div>
<div class="sales-overview-states">
@foreach(['Pendiente de pago','En proceso de pago','Atrasado','Pagado','Cancelado'] as $status)<div><span>{{ $status }}</span><strong>{{ $orderStates->get($status,0) }}</strong></div>@endforeach
</div>
<div class="sales-overview-health"><div><span>Parte vencida del saldo por cobrar</span><strong>{{ $overduePercent }}%</strong></div><div class="customer-overview-bar" role="progressbar" aria-label="Porcentaje de deuda vencida" aria-valuenow="{{ $overduePercent }}" aria-valuemin="0" aria-valuemax="100"><span style="width:{{ $overduePercent }}%"></span></div></div>
<p class="product-summary-footnote">Iniciales pendientes: <strong>{{ $money($initialCents) }}</strong> en {{ $initialCount }} {{ $initialCount===1?'venta':'ventas' }}. Las ventas canceladas se excluyen de la deuda.</p>
</section>
<section class="card sales-overview-credit">
<div class="product-summary-section-heading"><div><h2>Saldo a favor por aprovechar</h2><p>Crédito que puede cubrir deudas del mismo titular.</p></div><i data-lucide="badge-dollar-sign"></i></div>
<div class="sales-overview-credit-total"><strong data-sales-metric="applicable">{{ $money($applicableCents) }}</strong><span>disponible para aplicar a deudas actuales</span></div>
<div class="sales-overview-credit-accounts">
@forelse($creditAccounts as $account)<div><div><strong>{{ $account['client']->name }}</strong><small>Deuda {{ $money($account['debt_cents']) }} · Crédito {{ $money($account['credit_cents']) }}</small></div><a class="text-link" href="{{ $readOnlyPortfolio?route('head-office.clients.show',$account['client']):route('receipts.index',array_merge($allCredit,['client_id'=>$account['client']->id])) }}">{{ $readOnlyPortfolio?'Ver cliente':'Aplicar saldo' }}</a></div>
@empty<p class="product-summary-empty">{{ $creditCents>0?'El saldo a favor disponible queda para próximas compras; sus titulares no tienen deudas pendientes.':'No hay saldo a favor disponible para aplicar.' }}</p>@endforelse
</div>
<div class="sales-overview-receipt-states"><span>Pagos confirmados · Todo el historial</span>@foreach(['Sin aplicar','Parcialmente aplicado','Aplicado'] as $status)<div><span>{{ $status }}</span><strong>{{ $receiptStates->get($status,0) }}</strong></div>@endforeach</div>
<a class="product-summary-catalog-link" href="{{ route('receipts.index',$allCredit) }}">Ver pagos con saldo<i data-lucide="arrow-right"></i></a>
</section>
<section class="card">
<div class="product-summary-section-heading"><div><h2>Cobros por método</h2><p>Dinero recibido confirmado durante el período.</p></div><i data-lucide="wallet"></i></div>
<div class="sales-overview-methods">
@forelse($methods as $method)<div><div><strong>{{ $method['name'] }}</strong><b>{{ $money($method['amount_cents']) }}</b></div><div class="product-summary-type-track"><span style="width:{{ round($method['amount_cents']/$maxMethodCents*100,2) }}%"></span></div><small>{{ $method['count'] }} {{ $method['count']===1?'pago':'pagos' }}</small></div>
@empty<p class="product-summary-empty">Los métodos aparecerán cuando registres pagos en el período.</p>@endforelse
</div>
<p class="product-summary-footnote">El saldo a favor se mantiene separado de la deuda hasta que lo apliques.</p>
</section>
</aside>
</div>
</section>
@endsection
