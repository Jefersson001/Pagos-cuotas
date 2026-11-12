@extends('layouts.app')
@php($readOnlyPortfolio=$readOnlyPortfolio??false)
@section('title','Ventas')
@section('subtitle','Cada pedido, su avance y su calendario de pagos.')
@section('actions')@if($readOnlyPortfolio)<span class="pill neutral">Solo consulta</span>@else<a href="{{ route('orders.create') }}" class="btn primary"><i data-lucide="plus"></i>Nueva venta</a>@endif @endsection
@section('content')<section class="card table-card" data-live-panel><form class="filters live-filters receipt-list-filters" data-live-filters data-live-dates data-date-default="month" data-timezone="{{ config('app.timezone') }}">@include('components.dealership-filter')@include('components.monthly-date-filter')
<div class="search"><i data-lucide="search"></i><input name="q" value="{{ request('q') }}" placeholder="Buscar cliente, descripción o pedido"></div>
@if($readOnlyPortfolio)
<label>Concesionario<select name="concessionaire_id"><option value="">Todos los concesionarios</option>@foreach($concessionaires as $dealer)<option value="{{ $dealer->id }}" @selected(request('concessionaire_id')==$dealer->id)>{{ $dealer->name }}</option>@endforeach</select></label>
<label>Cliente<select name="client_id"><option value="">Todos los clientes</option>@foreach($clients as $client)<option value="{{ $client->id }}" @selected(request('client_id')==$client->id)>{{ $client->name }} · {{ $client->document }} · {{ $client->concessionaire->name }}</option>@endforeach</select></label>
@endif
<label>Jornada de venta<select name="sales_event_id"><option value="">Todas las jornadas</option>@foreach($salesEvents as $event)<option value="{{ $event->id }}" @selected(request('sales_event_id')==$event->id)>{{ $event->name }}</option>@endforeach</select></label></form>@include('orders.table',['orders'=>$orders])<div class="pagination">{{ $orders->links() }}</div></section>@endsection
