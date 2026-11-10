@extends('layouts.app')
@section('title',$module['name'])
@section('subtitle',$module['description'])
@section('content')
<div class="module-grid module-section-grid">@foreach($module['children'] as $child)<a class="module-card" href="{{ route($child['route']) }}"><div class="module-card-top"><span class="module-icon"><i data-lucide="{{ $module['icon'] }}"></i></span><i data-lucide="arrow-up-right"></i></div><div class="module-card-copy"><h2>{{ $child['name'] }}</h2><p>{{ $child['name']==='Clientes'?'Personas y empresas con sus pedidos y estado de cuenta.':($child['name']==='Concesionarios'?'Ficha comercial, datos de contacto y seguimiento de concesionarios.':($child['name']==='Pagos'?'Registra ingresos, aplica saldo a las ventas y consulta los comprobantes.':'Crea pedidos, programa cuotas y consulta el avance de cada venta.')) }}</p></div><div class="module-card-footer"><span>{{ $module['name'] }}</span><strong>Abrir <i data-lucide="arrow-right"></i></strong></div></a>@endforeach</div>
@endsection
