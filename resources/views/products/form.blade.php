@extends('layouts.app')
@section('title',$product->exists?'Editar producto':'Nuevo producto')
@section('subtitle','Define el precio y la inicial que se utilizarán al crear una venta.')
@section('actions')<a class="btn secondary" href="{{ route('products.index') }}">Volver a la lista</a>@endsection
@section('content')
<form method="post" action="{{ $product->exists ? route('products.update',$product) : route('products.store') }}" class="card form-card">
@csrf @if($product->exists)@method('PUT')@endif
<h2>Datos del producto</h2>
@if(auth()->user()->isAdmin())@if($product->exists)<p class="muted mb-6">Concesionario: {{ $product->dealership->name }}</p>@else<label>Concesionario<select name="dealership_id" required>@foreach($dealerships->where('active',true) as $dealer)<option value="{{ $dealer->id }}" @selected(old('dealership_id',$currentDealership?->id)==$dealer->id)>{{ $dealer->name }}</option>@endforeach</select></label>@endif @endif
<div class="form-grid"><label class="full">Nombre del producto<input name="name" value="{{ old('name',$product->name) }}" required maxlength="180" autofocus></label><label>Precio ($)<input type="number" name="price" min="0.01" max="9999999999.99" step="0.01" value="{{ old('price',$product->price) }}" required></label><label>Inicial ($)<input type="number" name="initial_amount" min="0" max="9999999999.99" step="0.01" value="{{ old('initial_amount',$product->initial_amount??'0.00') }}" required></label><label>Estado<select name="active"><option value="1" @selected(old('active',$product->active)==1)>Activo</option><option value="0" @selected(old('active',$product->active)==0)>Inactivo</option></select></label></div>
<p class="muted">La inicial se suma según la cantidad seleccionada. Los cambios de precio o inicial se aplican a las ventas nuevas.</p><div class="form-actions"><a class="btn secondary" href="{{ route('products.index') }}">Cancelar</a><button class="btn primary"><i data-lucide="check"></i>Guardar producto</button></div>
</form>
@if($product->exists)<form method="post" action="{{ route('products.destroy',$product) }}" class="mt-6" data-confirm="¿Eliminar este producto?">@csrf @method('DELETE')<button class="btn danger">Eliminar producto</button></form>@endif
@endsection