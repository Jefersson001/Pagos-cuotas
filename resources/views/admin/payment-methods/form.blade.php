@extends('layouts.app')
@section('title',$method->exists?(($readOnly??false)?$method->name:'Editar método de pago'):'Nuevo método de pago')
@section('subtitle','Define el nombre y la disponibilidad de esta forma de cobro.')
@section('actions')<a class="btn secondary" href="{{ route('payment-methods.index') }}">Volver a métodos de pago</a>@endsection
@section('content')
@if($method->exists)<x-record-actions type="method" :record="$method" :edit="route('payment-methods.edit',$method)" :view="route('payment-methods.show',$method)" :read-only="$readOnly??false" />@endif
<x-record-status :current="$method->exists?($method->active?'Activo':'Inactivo'):'Borrador'" />
<form method="post" action="{{ $method->exists?route('payment-methods.update',$method):route('payment-methods.store') }}" class="card form-card">
    @csrf @if($method->exists)@method('PUT')@endif
    <fieldset class="record-fields" @disabled($readOnly??false)><h2>Datos del método de pago</h2>@if(auth()->user()->isAdmin())@if($method->exists)<p class="muted mb-6">Empresa administradora: {{ $method->dealership->name }}</p>@else<label>Empresa administradora<select name="dealership_id" required>@foreach($dealerships->where('active',true) as $dealer)<option value="{{ $dealer->id }}" @selected(old('dealership_id',$currentDealership?->id)==$dealer->id)>{{ $dealer->name }}</option>@endforeach</select></label>@endif @endif
    <div class="form-grid"><label>Nombre<input name="name" value="{{ old('name',$method->name) }}" required maxlength="80" autofocus placeholder="Ej. Transferencia bancaria"></label><label>Estado<select name="active"><option value="1" @selected(old('active',$method->active)==1)>Activo</option><option value="0" @selected(old('active',$method->active)==0)>Inactivo</option></select></label></div>
    <p class="muted">Los métodos inactivos se conservan en el historial y dejan de aparecer al registrar nuevos pagos.</p>
</fieldset>@unless($readOnly??false)<div class="form-actions"><a class="btn secondary" href="{{ route('payment-methods.index') }}">Volver a la lista</a><button class="btn primary"><i data-lucide="check"></i>Guardar método de pago</button></div>
@endunless</form>
@endsection
