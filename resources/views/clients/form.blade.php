@extends('layouts.app')
@section('title',$client->exists?'Editar cliente':'Nuevo cliente')
@section('subtitle','La información que necesitas para acompañar a tu cliente.')
@section('content')
<form method="post" action="{{ $client->exists?route('clients.update',$client):route('clients.store') }}" class="card form-card" x-data="clientForm(@js(['dealershipId'=>(string)$selectedDealershipId,'responsibleId'=>(string)old('user_id',$client->user_id),'users'=>$users,'url'=>route('clients.responsible-users')]))">
 @csrf @if($client->exists)@method('PUT')@endif
 <h2>Información personal</h2>
 <div class="form-grid">
  @foreach(['name'=>'Nombre y apellido','document'=>'Cédula / Identificación','phone'=>'Teléfono','whatsapp'=>'WhatsApp (opcional)','email'=>'Correo electrónico'] as $field=>$label)
   <label>{{ $label }}<input name="{{ $field }}" value="{{ old($field,$client->$field) }}" type="{{ $field==='email'?'email':(in_array($field,['phone','whatsapp'])?'tel':'text') }}" {{ in_array($field,['name','document','phone'])?'required':'' }} maxlength="{{ match($field){'document'=>80,'phone'=>40,'whatsapp'=>80,default=>255} }}" @if($field==='whatsapp')placeholder="+58 (412) 123-4567" aria-describedby="whatsapp-help"@endif>
   @if($field==='whatsapp')<small id="whatsapp-help" class="muted">Acepta espacios, guiones y paréntesis. Ej.: +58 412 1234567 o 0412-1234567.</small>@endif</label>
  @endforeach
  <label>Estado<select name="active"><option value="1" @selected(old('active',$client->active??1)==1)>Activo</option><option value="0" @selected(old('active',$client->active??1)==0)>Inactivo</option></select></label>
  <div class="full form-grid">
   @if(auth()->user()->isAdmin()&&!$client->exists)
    <label for="client-dealership">Concesionario<select id="client-dealership" name="dealership_id" x-model="dealershipId" @change="loadUsers()" required><option value="">Selecciona un concesionario</option>@foreach($dealerships->where('active',true) as $dealer)<option value="{{ $dealer->id }}" @selected($selectedDealershipId===$dealer->id)>{{ $dealer->name }}</option>@endforeach</select></label>
   @else
    <label>Concesionario<input value="{{ $client->exists?$client->dealership->name:$currentDealership?->name }}" readonly></label>
   @endif
   @if(auth()->user()->canManageUsers())
    <label for="client-responsible">Usuario responsable<select id="client-responsible" name="user_id" x-model="responsibleId" :disabled="loading" required :aria-busy="loading"><option value="" x-text="loading?'Cargando usuarios…':'Selecciona un usuario'"></option><template x-for="user in users" :key="user.id"><option :value="String(user.id)" x-text="user.name"></option></template></select></label>
    <p class="full muted" x-show="!loading && dealershipId && users.length===0 && !error" x-cloak>Este concesionario no tiene usuarios activos. <a class="text-link" href="{{ route('users.create') }}">Crear usuario</a></p>
    <div class="full notice error" role="alert" x-show="error" x-cloak><span x-text="error"></span><button type="button" class="text-link" @click="loadUsers()">Reintentar</button></div>
   @endif
  </div>
  <label class="full">Dirección<textarea name="address" maxlength="2000">{{ old('address',$client->address) }}</textarea></label>
  <label class="full">Observaciones<textarea name="notes" maxlength="3000">{{ old('notes',$client->notes) }}</textarea></label>
 </div>
 <div class="form-actions"><a href="{{ route('clients.index') }}" class="btn secondary">Volver</a><button class="btn primary" @if(auth()->user()->canManageUsers()):disabled="loading || !responsibleId || !!error"@endif><i data-lucide="check"></i>Guardar cliente</button></div>
</form>
@endsection