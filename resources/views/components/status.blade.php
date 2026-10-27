@props(['status'])<span class="status {{ match($status){'Pagado'=>'green','Atrasado'=>'red','Cancelado'=>'gray',default=>'blue'} }}"><span></span>{{ $status }}</span>
