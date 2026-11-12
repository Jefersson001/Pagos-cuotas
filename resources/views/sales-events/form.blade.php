@extends('layouts.app')
@section('title',$event->exists?(($readOnly??false)?$event->name:'Editar jornada de venta'):'Nueva jornada de venta')
@section('actions')
<a class="btn secondary" href="{{ route('sales-events.index') }}">Volver a jornadas</a>
@if($event->exists && ($readOnly??false))@can('update',$event)<a class="btn primary" href="{{ route('sales-events.edit',$event) }}">Editar</a>@endcan @endif
@endsection
@section('content')
<form method="post" action="{{ $event->exists?route('sales-events.update',$event):route('sales-events.store') }}" class="card form-card">
    @csrf @if($event->exists)@method('PUT')@endif
    <fieldset class="record-fields" @disabled($readOnly??false)>
        <div class="record-card-heading"><h2>Datos de la jornada</h2><x-record-status :current="$event->exists?($event->active?'Activo':'Inactivo'):'Borrador'" /></div>
        @if(auth()->user()->isAdmin())
            @if($event->exists)<p class="muted mb-6">Empresa principal: {{ $event->dealership->name }}</p>
            @else<label>Empresa principal<select name="dealership_id" required>@foreach($dealerships->where('active',true) as $company)<option value="{{ $company->id }}" @selected(old('dealership_id',$currentDealership?->id)==$company->id)>{{ $company->name }}</option>@endforeach</select></label>@endif
        @endif
        <div class="form-grid">
            <label>Nombre de la jornada<input name="name" value="{{ old('name',$event->name) }}" required maxlength="100" autofocus placeholder="Ej. Evento Motor Extreme"></label>
            <label>Estado<select name="active"><option value="1" @selected(old('active',$event->active)==1)>Activo</option><option value="0" @selected(old('active',$event->active)==0)>Inactivo</option></select></label>
        </div>
        <input type="hidden" name="is_default" value="0">
        <label class="credit-checkbox"><input type="checkbox" name="is_default" value="1" @checked(old('is_default',$event->is_default))>Jornada normal (predeterminada)</label>
        <p class="muted">Para ventas habituales desde el concesionario. Solo una jornada será la predeterminada de la empresa.</p>
    </fieldset>
    @unless($readOnly??false)<div class="form-actions"><a class="btn secondary" href="{{ route('sales-events.index') }}">Cancelar</a><button class="btn primary"><i data-lucide="check"></i>Guardar jornada</button></div>@endunless
</form>
@endsection