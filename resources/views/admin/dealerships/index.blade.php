@extends('layouts.app')
@section('title','Organización')
@section('subtitle','Relaciona cada concesionario con su casa matriz.')
@section('actions')<a class="btn primary" href="{{ route('dealerships.create') }}"><i data-lucide="plus"></i>Nueva organización</a>@endsection
@section('content')
<section class="card table-card"><div class="table-scroll"><table><thead><tr><th>Nombre</th><th>Tipo / Casa matriz</th><th>Código</th><th>Usuarios</th><th>Clientes</th><th>Estado</th><th>Acciones</th></tr></thead><tbody>
@forelse($rows as $dealer)<tr data-row-link="{{ route('dealerships.show',$dealer) }}"><td><strong>{{ $dealer->name }}</strong></td><td>{{ $dealer->organization_type==='head_office'?'Casa matriz':'Concesionario' }}<small>{{ $dealer->parent?->name }}</small></td><td>{{ $dealer->code }}</td><td>{{ $dealer->users_count }}</td><td>{{ $dealer->clients_count }}</td><td>{{ $dealer->active?'Activo':'Inactivo' }}</td><td><a class="text-link" href="{{ route('dealerships.edit',$dealer) }}">Editar</a></td></tr>@empty<tr><td colspan="7" class="empty">No hay organizaciones registradas.</td></tr>@endforelse
</tbody></table></div><div class="pagination">{{ $rows->links() }}</div></section>
@endsection