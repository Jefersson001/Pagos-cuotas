@props(['concessionaires'=>collect(),'canFilterConcessionaires'=>false])
<form class="filters live-filters card mb-6 overview-filters" data-live-filters data-live-dates data-date-default="month" data-timezone="{{ config('app.timezone') }}">
    <input type="hidden" name="date_mode" value="{{ request('date_mode','month') }}">
    @if(auth()->user()->isAdmin() && request()->has('dealership_id'))<input type="hidden" name="dealership_id" value="{{ request('dealership_id') }}">@endif
    <label>Desde<input type="date" name="from" value="{{ request('from') }}" max="{{ today()->toDateString() }}" required></label>
    <label>Hasta<input type="date" name="to" value="{{ request('to') }}" max="{{ today()->toDateString() }}" required></label>
    @if($canFilterConcessionaires)
        <label class="overview-dealer-filter">Concesionario<select name="concessionaire_id"><option value="">Todos los concesionarios</option>@foreach($concessionaires as $dealer)<option value="{{ $dealer->id }}" @selected(request('concessionaire_id')==$dealer->id)>{{ $dealer->name }}</option>@endforeach</select></label>
    @endif
    <button class="btn secondary" type="submit">Consultar</button>
    <a class="text-link" href="{{ request()->url().'?'.http_build_query(array_merge(request()->only('concessionaire_id','dealership_id'),['date_mode'=>'month'])) }}">Mes actual</a>
</form>
