@extends('layouts.app')
@section('title','Resumen de productos')
@section('subtitle','El estado de tu catálogo y los productos que impulsan tus ventas.')
@section('actions')
<a class="btn secondary" href="{{ route('products.index') }}">Ver catálogo</a>
@can('create',\App\Models\Product::class)<a class="btn primary" href="{{ route('products.create') }}"><i data-lucide="plus"></i>Nuevo producto</a>@endcan
@endsection
@section('content')
@php($money=fn($cents)=>'$'.number_format($cents/100,2))
<div class="customer-overview-period"><span><i data-lucide="calendar-days"></i>Ventas del {{ $monthStart->format('d/m/Y') }} al {{ $updatedAt->format('d/m/Y') }}</span><small>Actualizado a las {{ $updatedAt->format('H:i') }}</small></div>
<div class="product-summary-kpis" data-product-overview>
@foreach([
['layers','Productos en el catálogo',$total,$active.' '.($active===1?'activo':'activos').' · '.($total-$active).' '.(($total-$active)===1?'inactivo':'inactivos'),'total'],
['package-check','Unidades vendidas',$unitsMonth,$ordersMonth.' '.($ordersMonth===1?'venta':'ventas').' con productos este mes','units'],
['chart-no-axes-combined','Importe vendido',$money($soldMonthCents),'Valor de productos vendidos este mes','sold'],
['trending-up','Productos con ventas',$sellingProducts,'Productos distintos vendidos este mes','selling']
] as [$icon,$label,$value,$detail,$key])
<section class="card product-summary-kpi"><div><span>{{ $label }}</span><i data-lucide="{{ $icon }}"></i></div><strong data-product-metric="{{ $key }}">{{ $value }}</strong><small>{{ $detail }}</small></section>
@endforeach
</div>
<div class="product-summary-layout">
<div class="product-summary-main">
<section class="card product-summary-chart">
<div class="product-summary-section-heading"><div><h2>Ventas del mes</h2><p>Importe de los productos vendidos, agrupado por semana.</p></div><span class="product-summary-tag">{{ $newThisMonth }} {{ $newThisMonth===1?'nuevo':'nuevos' }} en el catálogo</span></div>
<div class="product-summary-bars" aria-label="Importe vendido por semana">
@foreach($weeks as $week)<div class="product-summary-bar-row"><span>{{ $week['label'] }}</span><div class="product-summary-track"><span style="width:{{ round($week['amount']/$maxWeekCents*100,2) }}%"></span></div><strong>{{ $money($week['amount']) }}</strong></div>@endforeach
</div>
<p class="product-summary-footnote">Precios registrados al vender. Excluye ventas canceladas y conceptos sin producto del catálogo.</p>
</section>
<section class="card product-summary-ranking">
<div class="product-summary-section-heading"><div><h2>Los más vendidos</h2><p>Hasta 5 productos, ordenados por unidades del mes.</p></div><i data-lucide="trophy"></i></div>
<div class="table-scroll"><table><thead><tr><th>Producto</th><th>Unidades</th><th>Importe vendido</th><th>Acciones</th></tr></thead><tbody>
@forelse($ranking as $row)<tr @can('update',$row['product']) data-row-link="{{ route('products.show',$row['product']) }}" @endcan><td><div class="product-summary-product"><span class="product-summary-position">{{ $loop->iteration }}</span><div><strong>{{ $row['product']->name }}</strong><small>{{ $row['product']->sku?:'Sin referencia' }} · {{ $row['product']->active?'Activo':'Inactivo' }}</small></div></div></td><td>{{ $row['units_month'] }}</td><td><strong>{{ $money($row['sold_month_cents']) }}</strong></td><td>@can('update',$row['product'])<a class="text-link" href="{{ route('products.show',$row['product']) }}">Ver producto</a> <a class="text-link" href="{{ route('products.edit',$row['product']) }}">Editar</a>@endcan</td></tr>
@empty<tr><td colspan="4" class="empty">Todavía no hay ventas de productos este mes.</td></tr>@endforelse
</tbody></table></div>
</section>
<section class="card">
<div class="product-summary-section-heading"><div><h2>Sin movimiento este mes</h2><p>{{ $withoutMovement }} {{ $withoutMovement===1?'producto activo sin ventas':'productos activos sin ventas' }} en el período. Mostrando hasta 5.</p></div><i data-lucide="search"></i></div>
<div class="product-summary-quiet">
@forelse($quietProducts as $row)<div><div><strong>{{ $row['product']->name }}</strong><small>{{ $row['last_sale']?'Última venta: '.\Illuminate\Support\Carbon::parse($row['last_sale'])->format('d/m/Y'):'Sin ventas registradas' }}</small></div><strong>{{ $money(\App\Support\Money::cents($row['product']->price)) }}</strong>@can('update',$row['product'])<a class="text-link" href="{{ route('products.show',$row['product']) }}" aria-label="Revisar {{ $row['product']->name }}"><i data-lucide="arrow-up-right"></i></a>@endcan</div>
@empty<p class="product-summary-empty">{{ $active>0?'Todos los productos activos tuvieron ventas este mes.':'Agrega productos para comenzar a construir tu catálogo.' }}</p>@endforelse
</div>
</section>
</div>
<aside class="product-summary-side">
<section class="card">
<div class="product-summary-section-heading"><div><h2>Tu catálogo</h2><p>Distribución actual por tipo.</p></div><i data-lucide="shapes"></i></div>
<div class="product-summary-types">
@foreach($types as $type=>$group)<div><div><span class="product-summary-type-icon"><i data-lucide="{{ match($type){'vehicle'=>'car','service'=>'wrench',default=>'package'} }}"></i></span><span><strong>{{ $group['label'] }}</strong><small>{{ $group['active'] }} {{ $group['active']===1?'activo':'activos' }}</small></span><b>{{ $group['total'] }}</b></div><div class="product-summary-type-track"><span style="width:{{ $total>0?round($group['total']/$total*100,2):0 }}%"></span></div></div>@endforeach
</div>
<a class="product-summary-catalog-link" href="{{ route('products.index') }}">Explorar catálogo<i data-lucide="arrow-right"></i></a>
</section>
<section class="card">
<div class="product-summary-section-heading"><div><h2>Precios y planes</h2><p>Condiciones de los productos activos.</p></div><i data-lucide="calculator"></i></div>
<dl class="product-summary-plans"><div><dt>Precio de venta promedio</dt><dd>{{ $money($averagePriceCents) }}</dd></div><div><dt>Rango de precios</dt><dd>{{ $active>0?$money($minPriceCents).' – '.$money($maxPriceCents):'—' }}</dd></div><div><dt>Inicial promedio</dt><dd>{{ $money($averageInitialCents) }}</dd></div><div><dt>Con saldo a financiar</dt><dd>{{ $financedCount }} {{ $financedCount===1?'producto':'productos' }}</dd></div><div><dt>Plan más frecuente</dt><dd>{{ $mostUsedPlan!==null?$mostUsedPlan.' cuotas':'—' }}</dd></div></dl>
<p class="product-summary-footnote">Son condiciones del catálogo actual; cada venta conserva su propio plan.</p>
</section>
<section class="card product-summary-review">
<div class="product-summary-section-heading"><div><h2>Para revisar</h2><p>Oportunidades para completar tu catálogo activo.</p></div><i data-lucide="clipboard-list"></i></div>
<div class="product-summary-checks"><div><span>Sin fotografía</span><strong>{{ $withoutImage }}</strong></div><div><span>Sin referencia / código</span><strong>{{ $withoutSku }}</strong></div><div><span>Sin ninguna venta registrada</span><strong>{{ $neverSold }}</strong></div></div>
<p class="product-summary-footnote">Las fotografías y referencias son opcionales. Los movimientos corresponden a las ventas que puedes consultar.</p>
</section>
</aside>
</div>
@endsection