@extends('layouts.app')
@section('title','Resumen de concesionarios')
@section('subtitle','Ventas, cobros y cartera de los concesionarios de tu casa matriz.')
@section('actions')<a class="btn primary" href="{{ route('clients.create',['customer_kind'=>'dealership']) }}">Nuevo concesionario</a><a class="btn secondary" href="{{ route('clients.index') }}">Ver concesionarios</a><a class="btn secondary" href="{{ route('users.create') }}">Crear usuario</a>@endsection
@section('content')
@php($money=fn($cents)=>'$'.number_format($cents/100,2))
<div class="customer-overview-period"><span>{{ $company->name }} · Movimientos del {{ $monthStart->format('d/m/Y') }} al {{ $updatedAt->format('d/m/Y') }}</span><small>Saldos actuales de toda la cartera</small></div>
<div class="product-summary-kpis">
@foreach([['Concesionarios',$rows->count()],['Clientes atendidos',$rows->sum('clients')],['Vendido este mes',$money($rows->sum('sold_cents'))],['Recibido este mes',$money($rows->sum('received_cents'))]] as [$label,$value])<section class="card product-summary-kpi"><div><span>{{ $label }}</span></div><strong>{{ $value }}</strong></section>@endforeach
</div>
<section class="card table-card"><div class="product-summary-section-heading"><div><h2>Resultados por concesionario</h2><p>Información consolidada de tus concesionarios. Los saldos corresponden a sus clientes finales.</p></div></div><div class="table-scroll"><table><thead><tr><th>Concesionario</th><th>Clientes</th><th>Ventas del mes</th><th>Vendido</th><th>Recibido</th><th>Cartera de clientes</th><th>Cartera vencida</th><th>Saldo a favor</th><th>Acciones</th></tr></thead><tbody>
@forelse($rows as $row)<tr data-row-link="{{ route('clients.show',$row['id']) }}"><td><strong>{{ $row['name'] }}</strong><small>{{ $row['code'] }} · {{ $row['active']?'Activo':'Inactivo' }}</small></td><td>{{ $row['clients'] }}</td><td>{{ $row['sales_month'] }}</td><td>{{ $money($row['sold_cents']) }}</td><td>{{ $money($row['received_cents']) }}</td><td>{{ $money($row['debt_cents']) }}</td><td>{{ $money($row['overdue_cents']) }}</td><td>{{ $money($row['credit_cents']) }}</td><td><a class="text-link" href="{{ route('clients.show',$row['id']) }}">Ver ficha</a></td></tr>
@empty<tr><td colspan="9" class="empty">Todavía no hay concesionarios relacionados con esta casa matriz.</td></tr>@endforelse
</tbody><tfoot><tr><td><strong>Total</strong></td><td>{{ $rows->sum('clients') }}</td><td>{{ $rows->sum('sales_month') }}</td>@foreach(['sold_cents','received_cents','debt_cents','overdue_cents','credit_cents'] as $field)<td><strong>{{ $money($rows->sum($field)) }}</strong></td>@endforeach<td></td></tr></tfoot></table></div></section>
@endsection
