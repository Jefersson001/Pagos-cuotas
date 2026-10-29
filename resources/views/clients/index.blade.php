@extends('layouts.app')
@section('title','Concesionarios')
@section('subtitle','Empresas a las que Bera registra ventas, pagos y saldos.')
@section('actions')<a class="btn primary" href="{{ route('clients.create') }}"><i data-lucide="user-plus"></i>Nuevo concesionario</a>@endsection
@section('content')
<section class="card table-card" data-live-panel><form class="filters live-filters" data-live-filters>@if($dealerships->count()>1)@include('components.dealership-filter')@endif
<div class="search"><i data-lucide="search"></i><input name="q" value="{{ request('q') }}" placeholder="Razón social, RIF, ciudad, representante o teléfono" aria-label="Consultar concesionarios"></div>
<select name="business_type" aria-label="Tipo de empresa"><option value="">Todos los tipos</option>@foreach(['CONCESIONARIO','TALLER','CONCESIONARIO/ INTEGRAL'] as $type)<option value="{{ $type }}" @selected(request('business_type')===$type)>{{ $type }}</option>@endforeach</select>
</form>
<div class="table-scroll"><table><thead><tr><th>Tipo</th><th>Cliente</th><th>Condición</th><th>Fecha de inicio</th><th>Representante legal</th><th>Dirección</th><th>Ciudad</th><th>Estado</th><th>RIF</th><th>Teléfono</th><th>Correo</th><th></th></tr></thead><tbody>
@forelse($clients as $c)<tr data-row-link="{{ route('clients.show',$c) }}"><td>{{ $c->business_type }}</td><td><strong>{{ $c->name }}</strong></td><td>{{ $c->condition ?: '—' }}</td><td>{{ $c->started_at?->format('d/m/Y') ?? '—' }}</td><td>{{ $c->legal_representative ?: '—' }}</td><td>{{ $c->address ?: '—' }}</td><td>{{ $c->city ?: '—' }}</td><td>{{ $c->state ?: '—' }}</td><td>{{ $c->document ?: '—' }}</td><td>{{ $c->phone ?: '—' }}</td><td>{{ $c->email ?: '—' }}</td><td><a class="text-link" href="{{ route('clients.show',$c) }}">Ver ficha <i data-lucide="chevron-right"></i></a></td></tr>
@empty<tr><td colspan="12" class="empty">No hay concesionarios que coincidan.</td></tr>@endforelse
</tbody></table></div>
<div class="pagination">{{ $clients->links() }}</div></section>
@endsection
