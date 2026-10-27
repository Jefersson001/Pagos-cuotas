<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Order;
use App\Models\Product;
use App\Queries\OrderQuery;
use App\Queries\ReceiptQuery;
use App\Support\Money;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class DashboardService
{
    public function build(Request $request): array
    {
        return DB::transaction(fn () => $this->snapshot($request));
    }

    private function snapshot(Request $request): array
    {
        $dateFrom = $request->input('from', today()->toDateString());
        $dateTo = $request->input('to', today()->toDateString());
        $dateMode = $request->input('date_mode', 'today');
        $inPeriod = fn ($date) => $date && $date->toDateString() >= $dateFrom && $date->toDateString() <= $dateTo;
        $scope = $request->duplicate();
        foreach (['from', 'to', 'status', 'client', 'user_id'] as $filter) {
            $scope->query->remove($filter);
        }
        $orders = OrderQuery::get($scope);
        $active = $orders->filter(fn ($o) => ! $o->cancelled && $o->balance_cents > 0);
        $active->filter(fn ($o) => Money::cents($o->initial_amount) > 0 && ! $o->installments->contains('number', 0))->load('payments.allocations');
        $installments = $active->flatMap->installments->filter(fn ($i) => $i->remaining_cents > 0)->sortBy('due_date');
        $receipts = ReceiptQuery::get($scope);
        $payments = $receipts->whereNull('voided_at');
        $periodPayments = $payments->filter(fn ($p) => $inPeriod($p->created_at));
        $periodOrders = $orders->filter(fn ($o) => $inPeriod($o->created_at));
        $periodActive = $periodOrders->filter(fn ($o) => ! $o->cancelled && $o->balance_cents > 0);
        $clients = Client::visibleTo($request->user())->with(['owner', 'dealership'])->get();
        $productStats = Product::visibleTo($request->user())->selectRaw('COUNT(*) as total, COALESCE(SUM(CASE WHEN active = 1 THEN 1 ELSE 0 END),0) as active_total, COALESCE(SUM(CASE WHEN created_at >= ? AND created_at < ? THEN 1 ELSE 0 END),0) as new_total', [$dateFrom.' 00:00:00', Carbon::parse($dateTo)->addDay()->toDateString().' 00:00:00'])->first();
        $orderGroups = $orders->groupBy('client_id');
        $receiptGroups = $receipts->groupBy('client_id');
        $accounts = $clients->map(function ($client) use ($orderGroups, $receiptGroups, $inPeriod) {
            $clientOrders = $orderGroups->get($client->id, collect());
            $clientReceipts = $receiptGroups->get($client->id, collect())->whereNull('voided_at');
            $outstanding = $clientOrders->filter(fn ($o) => ! $o->cancelled && $o->balance_cents > 0);
            $pending = $outstanding->flatMap->installments->filter(fn ($i) => $i->remaining_cents > 0);
            $debt = $outstanding->sum('balance_cents');
            $credit = $clientReceipts->sum('available_cents');
            $overdue = $pending->filter(fn ($i) => $i->due_date->lt(today()))->sum('remaining_cents');
            $movements = $clientOrders->isNotEmpty() || $receiptGroups->get($client->id, collect())->isNotEmpty();
            $priority = match (true) {
                $overdue > 0 => 0,$debt > 0 && $credit > 0 => 1,$debt > 0 => 2,$credit > 0 => 3,$movements => 4,default => 5
            };

            return ['client' => $client, 'debt_cents' => $debt, 'credit_cents' => $credit, 'received_cents' => $clientReceipts->filter(fn ($p) => $inPeriod($p->created_at))->sum(fn ($p) => Money::cents($p->amount)), 'overdue_cents' => $overdue, 'applicable_cents' => min($debt, $credit), 'active_orders' => $outstanding->count(), 'next_due' => $pending->sortBy('due_date')->first()?->due_date, 'priority' => $priority, 'status' => match ($priority) {
                0 => 'Pagos vencidos',1 => 'Saldo por aplicar',2 => 'Deuda pendiente',3 => 'Saldo a favor',4 => 'Sin saldos',default => 'Sin movimientos'
            }];
        })->sort(function ($a, $b) {
            return ($a['priority'] <=> $b['priority']) ?: ($b['debt_cents'] <=> $a['debt_cents']) ?: ($b['credit_cents'] <=> $a['credit_cents']) ?: strcasecmp($a['client']->name, $b['client']->name) ?: ($a['client']->id <=> $b['client']->id);
        })->values();
        $summary = ['clients' => $clients->count(), 'active_clients' => $clients->where('active', true)->count(), 'products' => (int) $productStats->total, 'active_products' => (int) $productStats->active_total, 'debt_cents' => $accounts->sum('debt_cents'), 'credit_cents' => $accounts->sum('credit_cents'), 'received_cents' => $periodPayments->sum(fn ($p) => Money::cents($p->amount)), 'clients_with_debt' => $accounts->where('debt_cents', '>', 0)->count(), 'clients_with_credit' => $accounts->where('credit_cents', '>', 0)->count(), 'applicable_cents' => $accounts->sum('applicable_cents'), 'overdue_cents' => $accounts->sum('overdue_cents'), 'initial_cents' => $active->sum(fn ($order) => $this->initialRemaining($order)), 'today_cents' => $installments->filter(fn ($i) => $i->due_date->isToday())->sum('remaining_cents'), 'week_cents' => $installments->filter(fn ($i) => $i->due_date->betweenIncluded(today()->startOfWeek(), today()->endOfWeek()))->sum('remaining_cents')];
        $summary['new_clients'] = $clients->filter(fn ($c) => $inPeriod($c->created_at))->count();
        $summary['new_products'] = (int) $productStats->new_total;
        $summary['period_orders'] = $periodOrders->count();
        $summary['period_sold_cents'] = $periodOrders->where('cancelled', false)->sum(fn ($o) => Money::cents($o->total));
        $debtTop = $accounts->where('debt_cents', '>', 0)->sortByDesc('debt_cents')->take(5);
        $creditTop = $accounts->where('credit_cents', '>', 0)->sortByDesc('credit_cents')->take(5);
        $debtLabels = $debtTop->map(fn ($a) => $a['client']->name)->values();
        $debtValues = $debtTop->map(fn ($a) => $a['debt_cents'] / 100)->values();
        $creditLabels = $creditTop->map(fn ($a) => $a['client']->name)->values();
        $creditValues = $creditTop->map(fn ($a) => $a['credit_cents'] / 100)->values();
        $start = Carbon::parse($dateFrom);
        $end = Carbon::parse($dateTo);
        $unit = $start->diffInDays($end) <= 62 ? 'day' : ($start->diffInMonths($end) <= 120 ? 'month' : 'year');
        $keyFormat = match ($unit) {
            'day' => 'Y-m-d', 'month' => 'Y-m', default => 'Y'
        };
        $labelFormat = match ($unit) {
            'day' => 'd/m/Y', 'month' => 'm/Y', default => 'Y'
        };
        $buckets = $periodPayments->groupBy(fn ($p) => $p->created_at->format($keyFormat))->map(fn ($rows) => $rows->sum(fn ($p) => Money::cents($p->amount)));
        $months = collect();
        $chart = collect();
        $cursor = match ($unit) {
            'month' => $start->copy()->startOfMonth(), 'year' => $start->copy()->startOfYear(), default => $start->copy()
        };
        while ($cursor->lte($end)) {
            $months->push($cursor->format($labelFormat));
            $chart->push($buckets->get($cursor->format($keyFormat), 0) / 100);
            match ($unit) {
                'day' => $cursor->addDay(), 'month' => $cursor->addMonthNoOverflow(), default => $cursor->addYear()
            };
        }
        $filtered = $accounts;
        $page = max(1, $request->integer('page', 1));
        $accounts = new LengthAwarePaginator($filtered->forPage($page, 10), $filtered->count(), 10, $page, ['path' => $request->url(), 'query' => $request->query()]);

        return compact('orders', 'active', 'installments', 'payments', 'summary', 'accounts', 'months', 'chart', 'debtLabels', 'debtValues', 'creditLabels', 'creditValues', 'dateFrom', 'dateTo', 'dateMode', 'periodOrders', 'periodActive');
    }

    private function initialRemaining(Order $order): int
    {
        if (Money::cents($order->initial_amount) === 0) {
            return 0;
        }
        if ($initial = $order->installments->firstWhere('number', 0)) {
            return $initial->remaining_cents;
        }
        $confirmed = $order->payments->whereNull('voided_at');
        $paid = $confirmed->sum(fn ($payment) => $payment->is_initial ? Money::cents($payment->amount) : Money::cents($payment->amount) - $payment->allocations->sum(fn ($a) => Money::cents($a->amount)));

        return max(0, Money::cents($order->initial_amount) - $paid);
    }
}
