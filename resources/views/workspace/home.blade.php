@extends('layouts.app')
@section('title','Inicio')
@section('content')
@if(auth()->user()->isHeadOffice())
<section class="card role-shortcuts mb-6"><div><strong>{{ $pendingPayments }} {{ $pendingPayments===1?'pago pendiente de aprobación':'pagos pendientes de aprobación' }}</strong><p class="muted">Revisa lo registrado por tus concesionarios antes de actualizar los saldos de sus clientes.</p></div><a class="btn primary" href="{{ route('receipts.index',['state'=>'pending']) }}">Revisar pagos</a></section>
@elseif(auth()->user()->canOperateFinancial())
<nav class="role-shortcuts mb-6" aria-label="Acciones frecuentes"><a class="btn primary" href="{{ route('orders.create') }}">Nueva venta</a><a class="btn secondary" href="{{ route('receipts.create') }}">Registrar pago</a><a class="btn secondary" href="{{ route('collections') }}">Cuentas por cobrar</a></nav>
@endif
<section class="module-launcher" aria-labelledby="modules-title" x-data="{search:''}">
    <div class="launcher-heading">
        <div class="launcher-introduction">
            <svg class="launcher-symbol" viewBox="0 0 40 40" fill="currentColor" aria-hidden="true">
                <rect x="2" y="2" width="15" height="15" rx="5"/>
                <rect x="23" y="2" width="15" height="15" rx="5" opacity=".45"/>
                <rect x="2" y="23" width="15" height="15" rx="5" opacity=".65"/>
                <rect x="23" y="23" width="15" height="15" rx="5" opacity=".85"/>
            </svg>
            <div>
                <p class="eyebrow">Panel de control</p>
                <h1 id="modules-title">Tu espacio de trabajo</h1>
                <p>Accede a los módulos de tu negocio con <strong>Cuota.</strong></p>
            </div>
        </div>
        <label class="launcher-search">
            <i data-lucide="search" aria-hidden="true"></i>
            <input type="search" aria-label="Buscar módulo" placeholder="Buscar módulo…" x-model="search" autocomplete="off">
        </label>
    </div>
    <div class="module-grid">
        @foreach($modules as $module)
            <a class="module-card" href="{{ route($module['route']) }}" x-show="@js(mb_strtolower($module['name'])).includes(search.toLowerCase().trim())" data-module-card>
                <span class="module-icon" aria-hidden="true"><i data-lucide="{{ $module['icon'] }}"></i></span>
                <div class="module-card-content">
                    <div class="module-card-copy">
                        <h2>{{ $module['name'] }}<i data-lucide="arrow-up-right" aria-hidden="true"></i></h2>
                        <p>{{ $module['description'] }}</p>
                    </div>
                    <div class="module-card-footer"><strong>Abrir módulo <i data-lucide="arrow-right" aria-hidden="true"></i></strong></div>
                </div>
            </a>
        @endforeach
    </div>
    <p class="launcher-empty" role="status" x-show="search.trim() && !@js(collect($modules)->pluck('name')->map(fn($name)=>mb_strtolower($name))->values()).some(name=>name.includes(search.toLowerCase().trim()))" x-cloak>No se encontraron módulos con ese nombre.</p>
</section>
@endsection
