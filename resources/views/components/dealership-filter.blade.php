@if(auth()->user()->isAdmin())
<label class="dealership-filter" for="dealership-context">Empresa<select id="dealership-context" name="dealership_id" data-context-url="{{ route('dealership-context') }}"><option value="">Todas las empresas</option>@foreach($dealerships as $dealer)<option value="{{ $dealer->id }}" @selected($currentDealership?->id===$dealer->id)>{{ $dealer->name }}{{ $dealer->active?'':' (Inactivo)' }}</option>@endforeach</select></label>
@endif
