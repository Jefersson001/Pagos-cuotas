@extends('layouts.app')
@section('title','Métodos de pago')
@section('subtitle','Administra las formas de cobro disponibles para ventas y abonos.')
@section('actions')<a class="btn primary" href="{{ route('payment-methods.create') }}"><i data-lucide="plus"></i>Nuevo método de pago</a>@endsection
@section('content')
<section class="card table-card" data-live-panel>
    <form class="filters wrap live-filters" data-live-filters>@include('components.dealership-filter')

        <label>Buscar por nombre<input type="search" name="q" value="{{ request('q') }}" placeholder="Nombre del método" maxlength="80"></label>
        <label>Estado<select name="active"><option value="">Todos</option><option value="1" @selected(request('active')==='1')>Activo</option><option value="0" @selected(request('active')==='0')>Inactivo</option></select></label>
        <a class="text-link" href="{{ route('payment-methods.index') }}" data-clear-filters>Limpiar</a>
    </form>
    <div class="table-scroll"><table><thead><tr><th>ID</th><th>Nombre / Empresa administradora</th><th>Estado</th><th>Pagos registrados</th><th>Acciones</th></tr></thead><tbody>
    @forelse($methods as $method)
        <tr><td>{{ $method->id }}</td><td><a class="text-link" href="{{ route('payment-methods.edit',$method) }}">{{ $method->name }}</a><small>{{ $method->dealership->name }}</small></td><td><span class="status {{ $method->active?'green':'gray' }}">{{ $method->active?'Activo':'Inactivo' }}</span></td><td>{{ $method->payments_count }}</td><td><div class="flex items-center gap-4"><a class="text-link" href="{{ route('payment-methods.edit',$method) }}">Editar</a><form method="post" action="{{ route('payment-methods.destroy',$method) }}" data-confirm="¿Eliminar este método de pago? Esta acción no se puede deshacer.">@csrf @method('DELETE')<button class="text-link text-red-600">Eliminar</button></form></div></td></tr>
    @empty<tr><td colspan="5" class="empty">No hay métodos de pago con estos filtros.</td></tr>@endforelse
    </tbody></table></div><div class="pagination">{{ $methods->links() }}</div>
</section>
@endsection
