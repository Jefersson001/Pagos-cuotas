@extends('layouts.app')
@section('title',$client->exists?'Editar concesionario':'Nuevo concesionario')
@section('subtitle','Información del listado BERA CARS.')
@section('content')
<form method="post" action="{{ $client->exists?route('clients.update',$client):route('clients.store') }}" class="card form-card">
@csrf @if($client->exists)@method('PUT')@endif
<h2>Información del concesionario</h2>
<div class="form-grid product-details-grid">
<label>Tipo<select name="business_type">@foreach(['CONCESIONARIO','TALLER','CONCESIONARIO/ INTEGRAL'] as $type)<option value="{{ $type }}" @selected(old('business_type',$client->business_type??'CONCESIONARIO')===$type)>{{ $type }}</option>@endforeach</select></label>
<label>Cliente<input name="name" value="{{ old('name',$client->name) }}" required maxlength="255"></label>
<label>Condición<input name="condition" value="{{ old('condition',$client->condition) }}" maxlength="80"></label>
<label>Fecha de inicio<input type="date" name="started_at" value="{{ old('started_at',$client->started_at?->format('Y-m-d')) }}"></label>
<label>Representante legal<textarea name="legal_representative" maxlength="2000">{{ old('legal_representative',$client->legal_representative) }}</textarea></label>
<label>Dirección<textarea name="address" maxlength="2000">{{ old('address',$client->address) }}</textarea></label>
<label>Ciudad<input name="city" value="{{ old('city',$client->city) }}" maxlength="255"></label>
<label>Estado<input name="state" value="{{ old('state',$client->state) }}" maxlength="255"></label>
<label>RIF<input name="document" value="{{ old('document',$client->document) }}" maxlength="80"></label>
<label>Teléfono<input name="phone" value="{{ old('phone',$client->phone) }}" maxlength="255" type="tel"></label>
<label>Correo<input name="email" value="{{ old('email',$client->email) }}" maxlength="255"></label>
</div>
<div class="form-actions"><a href="{{ route('clients.index') }}" class="btn secondary">Volver</a><button class="btn primary"><i data-lucide="check"></i>Guardar concesionario</button></div>
</form>
@endsection
