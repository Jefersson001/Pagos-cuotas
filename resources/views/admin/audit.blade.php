@extends('layouts.app')
@section('title','Auditoría')
@section('subtitle','Trazabilidad de las operaciones y cambios importantes.')
@section('content')<section class="card table-card" data-live-panel>@if(auth()->user()->isAdmin())<form class="filters live-filters" data-live-filters>@include('components.dealership-filter')
@if(request('user_id'))<input type="hidden" name="user_id" value="{{ request('user_id') }}">@endif
</form>@endif<div class="table-scroll"><table><thead><tr><th>Fecha</th><th>Usuario / Concesionario</th><th>Acción</th><th>Registro</th><th>Detalle</th></tr></thead><tbody>@forelse($logs as $log)<tr><td>{{ $log->created_at }}</td><td>{{ $log->name }}<small>{{ $log->dealership_name??'Administración general' }}</small></td><td>{{ $log->action }}</td><td>{{ $log->entity }} #{{ $log->entity_id }}</td><td class="audit-detail">{{ $log->details }}</td></tr>@empty<tr><td colspan="5" class="empty">Sin operaciones registradas.</td></tr>@endforelse</tbody></table></div><div class="pagination">{{ $logs->links() }}</div></section>@endsection
