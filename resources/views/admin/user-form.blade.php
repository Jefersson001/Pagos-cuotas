<form method="post" action="{{ $u->exists?route('users.update',$u):route('users.store') }}" class="user-form">
 @csrf @if($u->exists)@method('PUT')@endif
 <div class="form-grid">
  <label>Nombre y apellido<input name="name" value="{{ old('name',$u->name) }}" required maxlength="255" autofocus></label>
  <label>Nombre de usuario<input name="username" value="{{ old('username',$u->username) }}" required maxlength="80" autocomplete="off"></label>
  <label>Correo<input name="email" type="email" value="{{ old('email',$u->email) }}" required maxlength="255" autocomplete="off"></label>
  <label>{{ $u->exists?'Nueva contraseña (opcional)':'Contraseña' }}<input name="password" type="password" minlength="12" maxlength="100" autocomplete="new-password" {{ $u->exists?'':'required' }} placeholder="Mínimo 12 caracteres"></label>
  <label>Rol<select name="role_id">@foreach($roles as $role)<option value="{{ $role->id }}" @selected(old('role_id',$u->role_id??$roles->firstWhere('name','user')?->id)==$role->id)>{{ match($role->name){'admin'=>'Administrador general','dealership_admin'=>'Administrador de concesionario',default=>'Asesor'} }}</option>@endforeach</select></label>
  @if(auth()->user()->isAdmin())<label>Concesionario<select name="dealership_id"><option value="">Sin asignar (administrador general)</option>@foreach($dealerships->filter(fn($dealer)=>$dealer->active||$dealer->id===$u->dealership_id) as $dealer)<option value="{{ $dealer->id }}" @selected(old('dealership_id',$u->dealership_id??($u->exists?null:$currentDealership?->id))==$dealer->id)>{{ $dealer->name }}{{ $dealer->active?'':' (Inactivo)' }}</option>@endforeach</select></label>@endif
  <label>Estado<select name="active"><option value="1" @selected(old('active',$u->active??true)==1)>Activo</option><option value="0" @selected(old('active',$u->active??true)==0)>Inactivo</option></select></label>
 </div>
 @if($u->exists)<p class="muted">Para restablecer el acceso, escribe una nueva contraseña. Si la dejas vacía, se conserva la actual.</p>@endif
 <div class="form-actions"><a class="btn secondary" href="{{ route('users') }}">Volver a la lista</a><button class="btn primary"><i data-lucide="check"></i>Guardar usuario</button></div>
</form>