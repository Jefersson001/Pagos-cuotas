@props(['summary'])
<section class="card table-card mb-6" data-client-affiliations>
    <div class="product-summary-section-heading">
        <div><h2>{{ auth()->user()->isAdmin()||auth()->user()->isHeadOffice()?'Clientes y concesionarios por mes':'Clientes afiliados por mes' }}</h2><p>{{ $summary['total'] }} clientes afiliados en {{ $summary['year'] }} · Primera venta.@if(auth()->user()->isAdmin()||auth()->user()->isHeadOffice()) {{ $summary['dealer_total'] }} concesionarios registrados · Fecha de inicio en el sistema.@endif</p></div>
        <form method="get" class="filters">
            @foreach(request()->except('affiliation_year','page') as $key=>$value)@if(is_scalar($value))<input type="hidden" name="{{ $key }}" value="{{ $value }}">@endif @endforeach
            <label>Año<input type="number" name="affiliation_year" min="1900" max="{{ today()->year }}" value="{{ $summary['year'] }}" required></label>
            <button class="btn secondary" type="submit">Consultar</button>
        </form>
    </div>
    <div class="table-scroll"><table><thead><tr><th>Tipo</th>@foreach($summary['months'] as $month)<th>{{ $month['label'] }}</th>@endforeach</tr></thead>
    <tbody><tr><th>Clientes</th>@foreach($summary['months'] as $month)<td data-affiliation-month="{{ $month['from'] }}"><a class="text-link" href="{{ route(auth()->user()->isHeadOffice()?'head-office.clients':'clients.index',array_merge(request()->only('concessionaire_id','dealership_id'),['customer_kind'=>'person','affiliation_from'=>$month['from'],'affiliation_to'=>$month['to']])) }}">{{ $month['count'] }}</a></td>@endforeach</tr>
    @if(auth()->user()->isAdmin()||auth()->user()->isHeadOffice())<tr><th>Concesionarios</th>@foreach($summary['months'] as $month)<td data-dealer-start-month="{{ $month['from'] }}"><a class="text-link" href="{{ route('clients.index',array_merge(request()->only('concessionaire_id','dealership_id'),['customer_kind'=>'dealership','started_from'=>$month['from'],'started_to'=>$month['to']])) }}">{{ $month['dealer_count'] }}</a></td>@endforeach</tr>@endif
    </tbody></table></div>
</section>
