<!DOCTYPE html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="csrf-token" content="{{ csrf_token() }}"><meta name="theme-color" content="#101010"><title>@yield('title','Inicio') · Cuota</title>@vite(['resources/css/app.css','resources/js/app.js'])</head>
<body @class(['workspace-shell', 'workspace-home' => request()->routeIs('home')]) x-data="{dark:localStorage.getItem('cuotas-dark')==='true'}" :class="{'dark-mode':dark}">
@php
$workspaceModules=auth()->user()->workspaceModules();
$isConcessionaire=request()->routeIs('concessionaires.*') || (isset($client) && $client->customer_kind==='dealership');
$activeModule=collect($workspaceModules)->first(fn($module)=>request()->routeIs(...$module['patterns']));
@endphp
<header class="workspace-header"><div class="workspace-header-inner">
<div class="workspace-header-left"><a href="{{ route('home') }}" class="brand"><span class="brand-icon"><i data-lucide="layers"></i></span><span class="workspace-brand-copy">cuota<span class="brand-dot">.</span>@if(request()->routeIs('home'))<small>GESTIÓN DE VENTAS Y PAGOS</small>@endif</span></a><a href="{{ route('home') }}" class="workspace-home-link" aria-label="Ir al inicio"><i data-lucide="layers"></i><span>Inicio</span></a>
@if($activeModule)<span class="workspace-module-title">{{ $activeModule['name'] }}</span>@endif</div>
<div class="workspace-header-right"><span class="workspace-date">{{ now()->format('d/m/Y') }}</span><button class="icon-button" @click="dark=!dark;localStorage.setItem('cuotas-dark',dark)" aria-label="Cambiar tema"><i data-lucide="sun-moon"></i></button>
<details class="workspace-user"><summary><span class="avatar small">{{ mb_substr(auth()->user()->name,0,1) }}</span><span>{{ auth()->user()->name }}</span><i data-lucide="chevron-right"></i></summary><div class="workspace-user-panel"><strong>{{ auth()->user()->name }}</strong><small>{{ auth()->user()->role_label }}</small><a href="{{ route(auth()->user()->isCustomer()?'portal.account':'workspace.settings') }}">Mi cuenta y ajustes</a><form method="post" action="{{ route('logout') }}">@csrf<button type="submit">Cerrar sesión</button></form></div></details></div>
</div></header>
@if($activeModule)<div class="workspace-navigation"><div class="workspace-navigation-inner"><details class="workspace-switcher"><summary><i data-lucide="layers"></i>Módulos</summary><div class="workspace-module-menu">@foreach($workspaceModules as $module)<a href="{{ route($module['route']) }}"><i data-lucide="{{ $module['icon'] }}"></i>{{ $module['name'] }}</a>@endforeach</div></details>
<nav aria-label="Secciones de {{ $activeModule['name'] }}">
@foreach($activeModule['children']??[['name'=>$activeModule['name'],'route'=>$activeModule['route']]] as $child)
    @if((empty($child['system'])||auth()->user()->isAdmin())&&(empty($child['manage'])||auth()->user()->canManageUsers())&&(empty($child['business'])||auth()->user()->canManageBusiness())&&(empty($child['organization'])||auth()->user()->canManageOrganizations()))
        @if(!empty($child['children']))
            <details class="workspace-nav-group" x-data @click.outside="$el.removeAttribute('open')" @keydown.escape="$el.removeAttribute('open');$el.querySelector('summary').focus()">
                <summary @class(['is-active'=>request()->routeIs(...$child['patterns'])])>{{ $child['name'] }}<i data-lucide="chevron-right" aria-hidden="true"></i></summary>
                <div class="workspace-nav-submenu">
                    @foreach($child['children'] as $entry)
                        @if(empty($entry['business'])||auth()->user()->canManageBusiness())
                            <a href="{{ route($entry['route']) }}" @class(['is-active'=>request()->routeIs(\Illuminate\Support\Str::beforeLast($entry['route'],'.').'.*')])>{{ $entry['name'] }}</a>
                        @endif
                    @endforeach
                </div>
            </details>
        @else
            <a href="{{ route($child['route']) }}" @class(['is-active'=>request()->routeIs($child['route']) || ($child['route']==='concessionaires.index'&&$isConcessionaire) || ($child['route']==='products.index'&&request()->routeIs('products.*')) || ($child['route']==='orders.index'&&request()->routeIs('orders.*')) || ($child['route']==='receipts.index'&&request()->routeIs('receipts.*')) || ($child['route']==='users'&&request()->routeIs('users.*')) || ($child['route']==='clients.index'&&request()->routeIs('clients.*','concessionaires.*'))])>{{ $child['name'] }}</a>
        @endif
    @endif
@endforeach
</nav></div></div>@endif
<main class="workspace-main">
@if($currentDealership && !$currentDealership->active)<div class="notice error" data-dealership-warning>La empresa está inactiva. Puedes consultar el historial.</div>@endif
@if(!request()->routeIs('home'))<div class="page-heading"><div><a class="workspace-back" href="{{ route('home') }}">← Volver al inicio</a><h1>@yield('title')</h1><p class="subtitle">@yield('subtitle')</p></div><div class="page-actions" data-page-actions>@yield('actions')</div></div>@endif
@if(session('success'))<div class="notice success" role="status"><i data-lucide="circle-check"></i>{{ session('success') }}</div>@endif
@if($errors->any())<div class="notice error" role="alert"><div><strong>Revisa la información</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div></div>@endif
@yield('content')
<footer>Cuota · Ventas que avanzan, cuentas claras.<span>{{ now()->year }} · Gestión de ventas y pagos</span></footer>
</main>@stack('scripts')</body></html>
