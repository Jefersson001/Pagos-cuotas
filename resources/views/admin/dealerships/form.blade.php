@extends('layouts.app')
@section('title',$dealership->exists?(($readOnly??false)?$dealership->name:'Editar organización'):'Nueva organización')
@section('subtitle','Configura los datos de tu organización.')
@section('content')
@if($dealership->exists)<x-record-actions type="company" :record="$dealership" :edit="route('dealerships.edit',$dealership)" :view="route('dealerships.show',$dealership)" :read-only="$readOnly??false" />@endif

<form method="post" action="{{ $dealership->exists?route('dealerships.update',$dealership):route('dealerships.store') }}" class="card form-card">
@csrf @if($dealership->exists)@method('PUT')@endif
<fieldset class="record-fields" @disabled($readOnly??false)><div class="record-card-heading"><h2>Datos de la organización</h2><x-record-status :current="$dealership->exists?($dealership->active?'Activo':'Inactivo'):'Borrador'" /></div><div class="form-grid">
<label>Nombre de la organización<input name="name" value="{{ old('name',$dealership->name) }}" required maxlength="255"></label>
<label>Código<input name="code" value="{{ old('code',$dealership->code) }}" required maxlength="40"></label>
<label>Teléfono<input name="phone" value="{{ old('phone',$dealership->phone) }}" maxlength="40"></label>
<label>Estado<select name="active"><option value="1" @selected(old('active',$dealership->active)==1)>Activo</option><option value="0" @selected(old('active',$dealership->active)==0)>Inactivo</option></select></label>
<label class="full">Dirección<textarea name="address" maxlength="2000">{{ old('address',$dealership->address) }}</textarea></label>
</div>
</fieldset>@unless($readOnly??false)<div class="form-actions"><a class="btn secondary" href="{{ route('dealerships.index') }}">Volver</a><button class="btn primary">Guardar organización</button></div>
@endunless</form>
@endsection
