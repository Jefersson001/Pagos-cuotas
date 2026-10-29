<?php

namespace App\Services;

use App\Queries\OrderQuery;
use App\Queries\ReceiptQuery;
use App\Support\Money;
use Illuminate\Http\Request;

class ReportService
{
    public const TYPES = ['sales' => 'Ventas realizadas', 'active' => 'Ventas activas', 'completed' => 'Pedidos completados', 'receipts' => 'Cobros recibidos', 'balances' => 'Saldos pendientes', 'overdue' => 'Cuotas vencidas', 'debtors' => 'Concesionarios con deudas', 'payments' => 'Historial de pagos', 'performance' => 'Rendimiento por usuario'];

    public function build(Request $r): array
    {
        $type = $r->input('type', 'sales');
        $copy = $r->duplicate();
        if (in_array($type, ['receipts', 'payments', 'overdue'])) {
            $copy->query->remove('from');
            $copy->query->remove('to');
        }
        $orders = OrderQuery::get($copy);
        $money = fn ($c) => Money::decimal($c);
        $inRange = fn ($d) => (! $r->filled('from') || $d->format('Y-m-d') >= $r->input('from')) && (! $r->filled('to') || $d->format('Y-m-d') <= $r->input('to'));
        $columns = ['Empresa', 'Pedido', 'Concesionario', 'Responsable', 'Total', 'Abonado', 'Saldo', 'Estado'];
        switch ($type) {
            case 'receipts':case 'payments':
                $columns = ['Empresa', 'Comprobante', 'Concesionario', 'Fecha', 'Recibido', 'Aplicado', 'Saldo a favor', 'Método', 'Referencia', 'Estado', 'Registrado por'];
                $receipts = ReceiptQuery::get($r);
                $rows = $receipts->filter(fn ($p) => $type === 'payments' || ! $p->voided_at)->map(fn ($p) => [$p->dealership->name, 'REC-'.$p->id, $p->client->name, $p->created_at->format('Y-m-d H:i'), $p->amount, $money($p->applied_cents), $money($p->available_cents), $p->method->name, $p->reference ?? '', $p->status, $p->recorder->name]);
                break;
            case 'overdue':
                $columns = ['Empresa', 'Pedido', 'Concesionario', 'Responsable', 'Cuota', 'Vencimiento', 'Días de atraso', 'Saldo'];
                $rows = $orders->where('cancelled', false)->flatMap(fn ($o) => $o->installments->filter(fn ($i) => $i->remaining_cents > 0 && $i->due_date->lt(today()) && $inRange($i->due_date))->map(fn ($i) => [$o->dealership->name, $o->id, $o->client->name, $o->owner->name, $i->label, $i->due_date->format('Y-m-d'), (int) $i->due_date->diffInDays(today()), $money($i->remaining_cents)]));
                break;
            case 'debtors':
                $columns = ['Empresa', 'Concesionario', 'RIF', 'Teléfono', 'Responsable', 'Pedidos con saldo', 'Deuda acumulada'];
                $rows = $orders->where('cancelled', false)->filter(fn ($o) => $o->balance_cents > 0)->groupBy('client_id')->map(fn ($group) => [$group->first()->dealership->name, $group->first()->client->name, $group->first()->client->document, $group->first()->client->phone, $group->first()->client->owner->name, $group->count(), $money($group->sum('balance_cents'))]);
                break;
            case 'performance':
                $columns = ['Empresa', 'Responsable', 'Ventas', 'Completadas', 'Total vendido', 'Aplicado a ventas', 'Saldo pendiente'];
                $rows = $orders->where('cancelled', false)->groupBy(fn ($o) => $o->dealership_id.':'.$o->user_id)->map(fn ($group) => [$group->first()->dealership->name, $group->first()->owner->name, $group->count(), $group->filter(fn ($o) => $o->status === 'Pagado')->count(), $money($group->sum(fn ($o) => Money::cents($o->total))), $money($group->sum('paid_cents')), $money($group->sum('balance_cents'))]);
                break;
            default:
                $selected = match ($type) {
                    'active','balances' => $orders->where('cancelled', false)->filter(fn ($o) => $o->balance_cents > 0),'completed' => $orders->filter(fn ($o) => $o->status === 'Pagado'),default => $orders
                };
                $rows = $selected->map(fn ($o) => [$o->dealership->name, $o->id, $o->client->name, $o->owner->name, $o->total, $money($o->paid_cents), $money($o->balance_cents), $o->status]);
        }

        return ['columns' => $columns, 'rows' => $rows->values(), 'reportTitle' => self::TYPES[$type], 'dateHint' => match ($type) {
            'receipts','payments' => 'Las fechas filtran el registro de los pagos.','overdue' => 'Las fechas filtran el vencimiento de las cuotas.',default => 'Las fechas filtran la creación de las ventas.'
        }];
    }
}
