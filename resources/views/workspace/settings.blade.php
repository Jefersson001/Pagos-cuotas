@extends('layouts.app')
@section('title','Resumen de ajustes')
@section('subtitle','Tu equipo y sus accesos a la vista.')
@section('actions')
@if(auth()->user()->canManageUsers())<a class="btn primary" href="{{ route('users.create',$contextParams) }}"><i data-lucide="user-plus"></i>Nuevo usuario</a>@endif
@endsection
@section('content')
<div class="customer-overview-period"><span><i data-lucide="calendar-days"></i>{{ $company?->name??'Todas las empresas' }} · Información actualizada al {{ $updatedAt->format('d/m/Y') }}</span><small>Actualizado a las {{ $updatedAt->format('H:i') }}</small></div>
@if(auth()->user()->canManageBusiness())
<div class="product-summary-kpis settings-overview-kpis" data-settings-overview>
@foreach([
['users-round','Usuarios registrados',$usersTotal,$usersActive.' '.($usersActive===1?'activo':'activos').' · '.$usersInactive.' '.($usersInactive===1?'inactivo':'inactivos'),'users'],
['user-round','Usuarios con acceso reciente',$usersRecentCount,'Último ingreso en los últimos 7 días','recent-users']
] as [$icon,$label,$value,$detail,$key])<section class="card product-summary-kpi"><div><span>{{ $label }}</span><i data-lucide="{{ $icon }}"></i></div><strong data-settings-metric="{{ $key }}">{{ $value }}</strong><small>{{ $detail }}</small></section>@endforeach
</div>
<div class="product-summary-layout settings-overview-layout">
<div class="product-summary-main">
<section class="card">
<div class="product-summary-section-heading"><div><h2>Tu equipo y sus roles</h2><p>Usuarios registrados en el ámbito seleccionado.</p></div>@if(auth()->user()->isAdmin())<a class="text-link" href="{{ route('users',$contextParams) }}">Ver usuarios</a>@endif</div>
<div class="settings-overview-roles">
@foreach($roles as $role)<div><div><strong>{{ $role['label'] }}</strong><b>{{ $role['total'] }}</b></div><div class="product-summary-type-track"><span style="width:{{ $usersTotal>0?round($role['total']/$usersTotal*100,1):0 }}%"></span></div><small>{{ $role['active'] }} {{ $role['active']===1?'activo':'activos' }}</small></div>@endforeach
</div>
<div class="sales-overview-applications"><div><span>Usuarios nuevos este mes</span><strong>{{ $usersNewMonth }}</strong></div><div><span>Activos sin un primer ingreso</span><strong>{{ $usersNeverLoggedIn }}</strong></div></div>
</section>
<section class="card settings-overview-users">
<div class="product-summary-section-heading"><div><h2>Accesos recientes</h2><p>Hasta 5 usuarios activos con ingreso desde el {{ $weekStart->format('d/m/Y') }}.</p></div><i data-lucide="user-round"></i></div>
<div class="table-scroll"><table><thead><tr><th>Usuario</th><th>Rol</th><th>Último ingreso</th><th>Acciones</th></tr></thead><tbody>
@forelse($recentUsers as $user)<tr @if(auth()->user()->isAdmin()) data-row-link="{{ route('users.show',$user) }}" @endif><td><strong>{{ $user->name }}</strong><small>{{ $user->dealership?->name??'Administración general' }}</small></td><td>{{ \App\Services\SettingsOverviewService::ROLE_LABELS[$user->role?->name]??'Usuario' }}</td><td>{{ $user->last_login_at->format('d/m/Y H:i') }}</td><td>@if(auth()->user()->isAdmin())<a class="text-link" href="{{ route('users.show',$user) }}">Ver usuario</a> <a class="text-link" href="{{ route('users.edit',$user) }}">Editar</a>@endif</td></tr>
@empty<tr><td colspan="4" class="empty">No hay ingresos registrados para usuarios activos en los últimos 7 días.</td></tr>@endforelse
</tbody></table></div>
<p class="product-summary-footnote">Se muestra el último inicio de sesión registrado; no indica quién está conectado ahora.</p>
</section>
</div>
<aside class="product-summary-side">
<section class="card">
<div class="product-summary-section-heading"><div><h2>Para revisar</h2><p>Datos útiles para organizar la configuración.</p></div><i data-lucide="clipboard-list"></i></div>
<div class="product-summary-checks"><div><span>Usuarios activos sin primer ingreso</span><strong>{{ $usersNeverLoggedIn }}</strong></div><div><span>Usuarios inactivos</span><strong>{{ $usersInactive }}</strong></div></div>
</section>
</aside>
</div>
@endif
<section class="card settings-overview-account">
<div class="product-summary-section-heading"><div><h2>Mi cuenta</h2><p>Tu acceso al sistema.</p></div><i data-lucide="user-round"></i></div>
<div class="settings-overview-account-grid"><div><small>Nombre</small><strong>{{ auth()->user()->name }}</strong></div><div><small>Correo</small><strong>{{ auth()->user()->email }}</strong></div><div><small>Rol</small><strong>{{ $roleLabel }}</strong></div><div><small>Último ingreso</small><strong>{{ auth()->user()->last_login_at?->format('d/m/Y H:i')??'Sin ingreso registrado' }}</strong></div></div>
</section>
@endsection
