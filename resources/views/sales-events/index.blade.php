@extends('layouts.app')
@section('title','Jornadas de venta')
@section('actions')@can('create',\App\Models\SalesEvent::class)<a class="btn primary" href="{{ route('sales-events.create') }}"><i data-lucide="plus"></i>Nueva jornada</a>@endcan <a class="btn secondary" target="_blank" rel="noopener" href="{{ route('sales-events.index',array_merge(request()->except(['page','print','export']),['dealership_id'=>$currentDealership?->id??'','print'=>1])) }}"><i data-lucide="printer"></i>Imprimir / PDF</a>@endsection
@section('content')
<section class="card table-card" data-live-panel>
    <form class="filters wrap live-filters" data-live-filters data-live-dates data-date-default="month" data-timezone="{{ config('app.timezone') }}">
        @include('components.dealership-filter')@include('components.monthly-date-filter')
        <label>Nombre de la jornada<input type="search" name="q" value="{{ request('q') }}" maxlength="100" placeholder="Buscar jornada"></label>
        @if(auth()->user()->isAdmin()||auth()->user()->isHeadOffice())<label>Concesionario<select name="concessionaire_id"><option value="">Todos los concesionarios</option>@foreach($concessionaires as $dealer)<option value="{{ $dealer->id }}" @selected(request('concessionaire_id')==$dealer->id)>{{ $dealer->name }}</option>@endforeach</select></label>@endif
        <label>Estado<select name="active"><option value="">Todos</option><option value="1" @selected(request('active')==='1')>Activo</option><option value="0" @selected(request('active')==='0')>Inactivo</option></select></label>
    </form>
    <div class="card-heading"><p class="muted">Ventas no canceladas, por fecha de creación. Compara cantidad e importe vendido; estos importes no representan cobros ni utilidad.</p></div>
    <div class="table-scroll"><table><thead><tr><th>Jornada / Empresa principal</th><th>Tipo</th><th>Estado</th><th>Ventas</th><th>Importe vendido</th><th>Acciones</th></tr></thead><tbody>
    @forelse($events as $event)
        <tr data-row-link="{{ route('sales-events.show',$event) }}"><td><a class="text-link" href="{{ route('sales-events.show',$event) }}">{{ $event->name }}</a><small>{{ $event->dealership->name }}</small></td><td>{{ $event->is_default?'Normal · Predeterminada':'Especial' }}</td><td><span class="status {{ $event->active?'green':'gray' }}">{{ $event->active?'Activo':'Inactivo' }}</span></td><td>{{ $event->sales_count }}</td><td>&#36;{{ number_format($event->sales_total??0,2) }}</td><td><div class="flex items-center gap-4"><a class="text-link" href="{{ route('sales-events.show',$event) }}">Ver</a>@can('update',$event)<a class="text-link" href="{{ route('sales-events.edit',$event) }}">Editar</a>@if(!$event->is_default)<form method="post" action="{{ route('sales-events.destroy',$event) }}" data-confirm="¿Eliminar esta jornada? Solo se puede eliminar si no tiene ventas registradas.">@csrf @method('DELETE')<button class="text-link text-red-600">Eliminar</button></form>@endif @endcan</div></td></tr>
    @empty<tr><td colspan="6" class="empty">No hay jornadas con estos filtros.</td></tr>@endforelse
    </tbody></table></div><div class="pagination">{{ $events->links() }}</div>
</section>
@endsection