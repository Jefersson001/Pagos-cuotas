@extends('layouts.app')
@php
$readOnlyPortfolio=$readOnlyPortfolio??false;
$profile=$client->customer_kind==='dealership'?['business_type'=>'Tipo','name'=>'Concesionario / Razón social','condition'=>'Condición','started_at'=>'Fecha de inicio','legal_representative'=>'Representante legal','address'=>'Dirección','city'=>'Ciudad','state'=>'Estado','document'=>'RIF','phone'=>'Teléfono','email'=>'Correo']:['name'=>'Nombre','document'=>'Identificación','phone'=>'Teléfono','email'=>'Correo','address'=>'Dirección','city'=>'Ciudad','state'=>'Estado / Región'];
$reportedAccounts=$client->customer_kind==='person'?$client->orders->where('cancelled',false)->map(fn($sale)=>$sale->reportedSummary($client->receipts)):collect();
$reportedCash=$client->receipts->where('approval_status','pending')->whereNull('voided_at')->sum(fn($receipt)=>\App\Support\Money::cents($receipt->amount));
@endphp

@section('title',$client->name)
@section('actions')@if($readOnlyPortfolio)<span class="pill neutral">Solo consulta</span><a class="btn secondary" href="{{ route('head-office.clients',['concessionaire_id'=>$client->concessionaire_id]) }}">Volver a clientes</a><a class="btn secondary" href="{{ route('clients.show',$client->concessionaire_id) }}">Ver concesionario</a>@elseif(auth()->user()->isHeadOffice())<a class="btn secondary" href="{{ route('head-office.clients',['concessionaire_id'=>$client->id]) }}">Ver clientes</a>@endif @if(auth()->user()->canOperateFinancial())<a class="btn secondary" href="{{ route('receipts.create',['client_id'=>$client->id]) }}">Registrar pago</a><a class="btn primary" href="{{ route('orders.create',['client_id'=>$client->id]) }}">Nueva venta</a>@endif @endsection
@section('content')
@if($client->exists&&!$readOnlyPortfolio)<x-record-actions type="client" :record="$client" :edit="route('clients.edit',$client)" :view="route('clients.show',$client)" :read-only="request()->routeIs('clients.show')" :can-edit="auth()->user()->can('update',$client)" :can-delete="auth()->user()->can('update',$client)&&!auth()->user()->isHeadOffice()" :can-duplicate="!auth()->user()->isHeadOffice()" />@endif


<x-view-tabs id="cliente-ficha" class="card">
<div data-tab-panel="Resumen" data-tab-key="resumen">
@if(auth()->user()->isHeadOffice()&&!$readOnlyPortfolio)
<section class="card form-card"><div class="record-card-heading"><h2>Resultados del concesionario</h2><x-record-status :current="$client->exists?($client->active?'Activo':'Inactivo'):'Borrador'" /></div><div class="detail-grid">@foreach(['clients'=>'Clientes atendidos','sales_month'=>'Ventas del mes','sold_cents'=>'Vendido este mes','received_cents'=>'Recibido este mes','debt_cents'=>'Cartera de sus clientes','overdue_cents'=>'Cartera vencida de sus clientes','credit_cents'=>'Saldo a favor'] as $key=>$label)<div><small>{{ $label }}</small><strong>{{ str_ends_with($key,'_cents')?'$'.number_format(($headOfficeResults[$key]??0)/100,2):($headOfficeResults[$key]??0) }}</strong></div>@endforeach</div></section>
@else
<section class="card form-card mb-6"><div class="record-card-heading"><h2>Estado de cuenta</h2><x-record-status :current="$client->exists?($client->active?'Activo':'Inactivo'):'Borrador'" /></div><div class="detail-grid"><div><small>Deuda de ventas</small><strong>${{ number_format($client->orders->where('cancelled',false)->sum('balance_cents')/100,2) }}</strong></div><div><small>Cobros reportados pendientes</small><strong>&#36;{{ number_format($reportedCash/100,2) }}</strong></div><div><small>Restante por cobrar considerando reportes</small><strong>&#36;{{ number_format($reportedAccounts->sum('remaining_cents')/100,2) }}</strong></div><div><small>Pagos recibidos confirmados</small><strong>${{ number_format($client->receipts->whereNull('voided_at')->where('approval_status','approved')->sum(fn($p)=>\App\Support\Money::cents($p->amount))/100,2) }}</strong></div><div><small>Saldo a favor sin aplicar</small><strong>${{ number_format($client->credit_cents/100,2) }}</strong><a class="text-link" href="{{ $readOnlyPortfolio ? route('head-office.clients.show',$client).'#cliente-ficha-pagos' : route('receipts.index',['client_id'=>$client->id,'state'=>'credit']) }}">Ver pagos disponibles</a></div></div></section>
@endif
</div>
<div data-tab-panel="Datos de contacto" data-tab-key="datos">
<section class="card form-card mb-6" data-concessionaire-details><h2>Ficha del {{ $client->customer_kind==='dealership'?'concesionario':'cliente' }}</h2><div class="detail-grid">@foreach($profile as $field=>$label)<div><small>{{ $label }}</small><strong>{{ $field==='started_at'?($client->started_at?->format('d/m/Y')??'—'):($client->$field?:'—') }}</strong></div>@endforeach @if($client->customer_kind==='dealership')<div><small>Organización</small><strong>{{ $client->dealership?->name??'Sin organización' }}</strong></div>@endif @if($client->customer_kind==='person')<div><small>Concesionario</small><strong>{{ $client->concessionaire?->name??'Sin concesionario' }}</strong></div>@endif</div></section>

</div>
@if(!auth()->user()->isHeadOffice()||$readOnlyPortfolio)
<div data-tab-panel="Ventas" data-tab-key="ventas">
<section class="card table-card"><div class="card-heading"><h2>Historial de ventas</h2></div>@include('orders.table',['orders'=>$client->orders])</section>

</div>
<div data-tab-panel="Pagos" data-tab-key="pagos">
<section class="card table-card mt-6"><div class="card-heading"><h2>Historial de pagos recibidos</h2></div><div class="table-scroll"><table><thead><tr><th>Comprobante</th><th>Fecha</th><th>Recibido</th><th>Aplicado</th><th>Saldo a favor</th><th>Método</th><th>Estado</th><th>Acciones</th></tr></thead><tbody>@forelse($client->receipts->sortByDesc('created_at') as $p)<tr data-row-link="{{ route($readOnlyPortfolio?'head-office.receipts.show':'receipts.show',$p) }}"><td><a class="text-link" href="{{ route($readOnlyPortfolio?'head-office.receipts.show':'receipts.show',$p) }}">REC-{{ str_pad($p->id,6,'0',STR_PAD_LEFT) }}</a></td><td>{{ $p->created_at->format('d/m/Y H:i') }}</td><td>${{ number_format($p->amount,2) }}</td><td>${{ number_format($p->applied_cents/100,2) }}</td><td>${{ number_format($p->available_cents/100,2) }}</td><td>{{ $p->method->name }}</td><td>{{ $p->status }}</td><td><a class="text-link" href="{{ route($readOnlyPortfolio?'head-office.receipts.show':'receipts.show',$p) }}">Ver pago</a> @can('update',$p)<a class="text-link" href="{{ route('receipts.edit',$p) }}">Editar</a>@endcan</td></tr>@empty<tr><td colspan="8" class="empty">Sin pagos registrados.</td></tr>@endforelse</tbody></table></div></section>
</div>
@endif
</x-view-tabs>
@endsection
