@extends('layouts.app')
@section('title','Ventas programadas')
@section('subtitle','Cada pedido, su avance y su calendario de pagos.')
@section('actions')<a href="{{ route('orders.create') }}" class="btn primary"><i data-lucide="plus"></i>Nueva venta</a>@endsection
@section('content')<section class="card table-card" data-live-panel><form class="filters live-filters" data-live-filters data-live-dates data-date-default="month" data-timezone="{{ config('app.timezone') }}">@include('components.dealership-filter')@include('components.monthly-date-filter')
<div class="search"><i data-lucide="search"></i><input name="q" value="{{ request('q') }}" placeholder="Buscar cliente, descripción o pedido"></div></form>@include('orders.table',['orders'=>$orders])<div class="pagination">{{ $orders->links() }}</div></section>@endsection
