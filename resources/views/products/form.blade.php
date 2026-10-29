@extends('layouts.app')
@section('title',$product->exists?'Editar producto':'Nuevo producto')
@section('subtitle','Características del vehículo y condiciones de venta.')
@section('actions')<a class="btn secondary" href="{{ route('products.index') }}">Volver a la lista</a>@endsection
@section('content')
@php
$vehicleImages = collect(config('product_images', []))->map(fn($vehicle) => ['label' => $vehicle['label'], 'aliases' => $vehicle['aliases'] ?? [], 'colors' => collect($vehicle['colors'])->map(fn($path) => asset($path))->all()])->all();
@endphp
<form method="post" action="{{ $product->exists ? route('products.update',$product) : route('products.store') }}" class="card form-card" x-data="productPlan(@js(['price'=>old('price',$product->price??''),'initial'=>old('initial_amount',$product->initial_amount??'0.00'),'count'=>old('installment_count',$product->installment_count??5),'model'=>old('model',$product->model??''),'color'=>old('color',$product->color??''),'images'=>$vehicleImages]))">
@csrf @if($product->exists)@method('PUT')@endif
<h2>Datos del producto</h2>
@if(auth()->user()->isAdmin())@if($product->exists)<p class="muted mb-6">Empresa administradora: {{ $product->dealership->name }}</p>@else<label>Empresa administradora<select name="dealership_id" required>@foreach($dealerships->where('active',true) as $dealer)<option value="{{ $dealer->id }}" @selected(old('dealership_id',$currentDealership?->id)==$dealer->id)>{{ $dealer->name }}</option>@endforeach</select></label>@endif @endif
<div class="form-grid product-details-grid">
<label>Marca<input name="brand" value="{{ old('brand',$product->brand) }}" required maxlength="60" autofocus placeholder="Ej. Bera"></label>
<label>Modelo<input name="model" x-model="vehicleModel" value="{{ old('model',$product->model) }}" required maxlength="80" placeholder="Ej. X3 Pro"></label>
<label>Transmisión<select name="transmission" required><option value="">Selecciona una opción</option>@foreach(\App\Models\Product::TRANSMISSIONS as $value=>$label)<option value="{{ $value }}" @selected(old('transmission',$product->transmission)===$value)>{{ $label }}</option>@endforeach</select></label>
<label>Año<input type="number" name="year" value="{{ old('year',$product->year) }}" required min="1900" max="{{ today()->year+1 }}" step="1" placeholder="{{ today()->year }}"></label>
<label>Precio contado ($)<input type="number" name="cash_price" min="0.01" max="9999999999.99" step="0.01" value="{{ old('cash_price',$product->cash_price) }}" required></label>
<label>Precio Tráetelo con Bera ($)<input type="number" name="price" x-model="price" min="0.01" max="9999999999.99" step="0.01" value="{{ old('price',$product->price) }}" required></label>
<label>Cuotas<input type="number" name="installment_count" x-model="count" min="1" max="120" step="1" value="{{ old('installment_count',$product->installment_count??5) }}" placeholder="Cantidad de cuotas" required></label>
<label>Inicial ($)<input type="number" name="initial_amount" x-model="initial" min="0" max="9999999999.99" step="0.01" value="{{ old('initial_amount',$product->initial_amount??'0.00') }}" required></label>
<label>Estado<select name="active"><option value="1" @selected(old('active',$product->active)==1)>Activo</option><option value="0" @selected(old('active',$product->active)==0)>Inactivo</option></select></label>
</div>
<div class="product-options-grid">
<fieldset class="product-color-field"><legend>Colores</legend><div class="product-colors">@foreach(\App\Models\Product::COLORS as $value=>$color)<label class="product-color-option"><input type="radio" name="color" x-model="color" value="{{ $value }}" @checked(old('color',$product->color)===$value) required><span class="product-color-circle" style="--vehicle-color:{{ $color['hex'] }}" aria-hidden="true"></span><span>{{ $color['label'] }}</span></label>@endforeach</div>
<div class="product-vehicle-preview" data-vehicle-preview>
<template x-if="vehicleImage && !imageFailed"><figure><img :src="vehicleImage" :alt="vehicleCaption" x-on:error="imageFailed=true" decoding="async" data-vehicle-image><figcaption x-text="vehicleCaption"></figcaption></figure></template>
<p class="muted" x-show="!vehicle" x-text="vehicleModel.trim() ? 'No hay fotografías disponibles para este modelo.' : 'Indica el modelo para ver sus fotografías.'" aria-live="polite"></p>
<p class="muted" x-show="vehicle && !vehicleImage" x-cloak>Selecciona un color para ver el vehículo.</p>
<p class="muted" x-show="imageFailed" x-cloak role="status">No se pudo cargar la fotografía del vehículo.</p>
</div>
</fieldset>
<section class="product-plan card table-card" aria-label="Previsualización del plan de pagos" data-product-plan>
<div class="card-heading"><div><h2>Previsualización del plan de pagos</h2><p>Inicial y cuotas por unidad del producto.</p></div></div>
<p class="muted product-plan-message" x-show="error" x-text="error" aria-live="polite"></p>
<div class="table-scroll" x-show="!error" x-cloak><table><thead><tr><th>Concepto</th><th>Monto</th></tr></thead><tbody>
<tr data-plan-initial><td><strong>Inicial</strong></td><td x-text="money(cents(initial))"></td></tr>
<template x-for="row in schedule" :key="row.number"><tr data-plan-installment><td x-text="'Cuota '+row.number"></td><td x-text="money(row.amount)"></td></tr></template>
</tbody><tfoot><tr><td><strong>Total</strong></td><td><strong data-plan-total x-text="money(cents(price))"></strong></td></tr></tfoot></table></div>
</section>
</div>
<p class="muted mb-6">El precio Tráetelo con Bera se utiliza al crear una venta programada.</p>
<p class="muted">La inicial se suma según la cantidad seleccionada. Los cambios de precio o inicial se aplican a las ventas nuevas.</p><div class="form-actions"><a class="btn secondary" href="{{ route('products.index') }}">Cancelar</a><button class="btn primary"><i data-lucide="check"></i>Guardar producto</button></div>
</form>
@if($product->exists)<form method="post" action="{{ route('products.destroy',$product) }}" class="mt-6" data-confirm="¿Eliminar este producto?">@csrf @method('DELETE')<button class="btn danger">Eliminar producto</button></form>@endif
@endsection
