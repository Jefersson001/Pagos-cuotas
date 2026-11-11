@props(['states'=>['Borrador','Activo','Inactivo'],'current'=>'Borrador'])
<span class="record-status-bar" aria-label="Estado del registro" data-record-status>
<span class="record-status-step is-current" aria-current="step">{{ $current }}</span>
</span>
