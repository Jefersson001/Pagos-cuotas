@extends('layouts.app')
@section('title','Usuarios')
@section('subtitle','Gestiona los accesos y responsables de tu equipo.')
@section('actions')<a class="btn primary" href="{{ route('users.create') }}"><i data-lucide="user-plus"></i>Crear nuevo usuario</a>@endsection
@section('content')
<section class="card table-card" data-live-panel>
 <form class="filters wrap live-filters" data-live-filters>@include('components.dealership-filter')
<label>Buscar usuario<input type="search" name="q" value="{{ request('q') }}" placeholder="Nombre, usuario o correo" maxlength="255"></label><label>Estado<select name="active"><option value="">Todos</option><option value="1" @selected(request('active')==='1')>Activo</option><option value="0" @selected(request('active')==='0')>Inactivo</option></select></label><a class="text-link" href="{{ route('users') }}" data-clear-filters>Limpiar</a></form>
 <div class="table-scroll"><table><thead><tr><th>Nombre / Correo</th><th>Usuario</th><th>Rol / Concesionario</th><th>Ventas</th><th>Último acceso</th><th>Estado</th><th>Acciones</th></tr></thead><tbody>
 @forelse($users as $u)<tr><td><a class="text-link" href="{{ route('users.edit',$u) }}">{{ $u->name }}</a><small>{{ $u->email }}</small></td><td>{{ $u->username }}</td><td>{{ match($u->role?->name){'admin'=>'Administrador general','dealership_admin'=>'Administrador de concesionario',default=>'Asesor'} }}<small>{{ $u->dealership?->name??'Acceso global' }}</small></td><td><a class="text-link" href="{{ route('reports',['user_id'=>$u->id,'type'=>'performance']) }}">{{ $u->orders_count }} · Ver rendimiento</a></td><td>{{ $u->last_login_at?->format('d/m/Y H:i')??'Sin acceso' }}</td><td><span class="status {{ $u->active?'green':'gray' }}">{{ $u->active?'Activo':'Inactivo' }}</span></td><td><div class="flex items-center gap-4"><a class="text-link" href="{{ route('users.edit',$u) }}">Editar</a><a class="text-link" href="{{ route('audit',['user_id'=>$u->id]) }}">Ver actividad</a></div></td></tr>
 @empty<tr><td colspan="7" class="empty">No hay usuarios con estos filtros.</td></tr>@endforelse
 </tbody></table></div><div class="pagination">{{ $users->links() }}</div>
</section>
@endsection