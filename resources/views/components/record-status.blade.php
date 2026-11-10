@props(['states'=>['Borrador','Activo','Inactivo'],'current'=>'Borrador'])
<nav class="record-status-bar" aria-label="Estado del registro" data-record-status>
@foreach($states as $state)<span @class(['record-status-step','is-current'=>$current===$state]) @if($current===$state) aria-current="step" @endif>{{ $state }}</span>@endforeach
</nav>