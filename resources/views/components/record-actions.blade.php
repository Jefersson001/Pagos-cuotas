@props(['type','record','edit'=>null,'view'=>null,'readOnly'=>false,'canEdit'=>true,'canDelete'=>true,'canDuplicate'=>true])
@if($record->exists)
<div class="record-actions" data-record-actions>
@if($readOnly)<span class="record-mode">Solo lectura</span>@elseif($view)<a class="btn secondary" href="{{ $view }}">Ver ficha</a>@endif
@if($canEdit)
@if($readOnly&&$edit)<a class="btn primary" href="{{ $edit }}">Editar</a>@endif
@if($canDuplicate)<a class="btn secondary" href="{{ route('records.duplicate',['type'=>$type,'id'=>$record->id]) }}">Duplicar</a>@endif
@endif
@if($canDelete)<form method="post" action="{{ route('records.destroy',['type'=>$type,'id'=>$record->id]) }}" data-confirm="{{ $type==='receipt'?'¿Eliminar este ingreso mediante una anulación? Se restaurarán las deudas y se conservará el historial.':'¿Eliminar este registro? Los registros con operaciones relacionadas se conservarán.' }}">@csrf @method('DELETE')<button class="btn danger">{{ $type==='receipt'?'Eliminar (anular ingreso)':'Eliminar registro' }}</button></form>@endif
</div>
@endif
