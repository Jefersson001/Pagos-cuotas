<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Order;
use App\Models\Receipt;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CustomerOverviewService
{
    public function build(Request $request): array
    {
        return DB::transaction(function () use ($request) {
            $now = now();
            $today = $now->copy()->startOfDay();
            $monthStart = $now->copy()->startOfMonth();
            $weekEnd = $today->copy()->addDays(6);
            $inMonth = fn ($date) => $date && $date->gte($monthStart) && $date->lte($now);
            $clients = Client::visibleTo($request->user())->get();
            $ids = $clients->modelKeys();
            $orders = Order::visibleTo($request->user())->whereIn('client_id', $ids)->with(['payments', 'installments.allocations.payment'])->get()->groupBy('client_id');
            $receipts = Receipt::visibleTo($request->user())->whereIn('client_id', $ids)->where('approval_status', 'approved')->where('approval_status', 'approved')->whereNull('voided_at')->with('applications')->get()->groupBy('client_id');
            $accounts = $clients->map(function ($client) use ($orders, $receipts, $today, $weekEnd, $inMonth) {
                $sales = $orders->get($client->id, collect())->where('cancelled', false);
                $pendingSales = $sales->filter(fn ($order) => $order->balance_cents > 0);
                $pending = $pendingSales->flatMap->installments->filter(fn ($installment) => $installment->remaining_cents > 0);
                $money = $receipts->get($client->id, collect());
                $monthSales = $sales->filter(fn ($order) => $inMonth($order->created_at));
                $debt = $pendingSales->sum('balance_cents');
                $credit = $money->sum('available_cents');
                $overdue = $pending->filter(fn ($installment) => $installment->due_date->lt($today))->sum('remaining_cents');
                $priority = match (true) {
                    $overdue > 0 => 0,
                    $debt > 0 && $credit > 0 => 1,
                    $debt > 0 => 2,
                    $credit > 0 => 3,
                    default => 4,
                };

                return [
                    'client' => $client,
                    'debt_cents' => $debt,
                    'credit_cents' => $credit,
                    'overdue_cents' => $overdue,
                    'due_soon_cents' => $pending->filter(fn ($installment) => $installment->due_date->betweenIncluded($today, $weekEnd))->sum('remaining_cents'),
                    'applicable_cents' => min($debt, $credit),
                    'received_month_cents' => $money->filter(fn ($receipt) => $inMonth($receipt->created_at))->sum(fn ($receipt) => Money::cents($receipt->amount)),
                    'sold_month_cents' => $monthSales->sum(fn ($order) => Money::cents($order->total)),
                    'sales_month' => $monthSales->count(),
                    'open_orders' => $pendingSales->count(),
                    'unapplied_receipts' => $money->filter(fn ($receipt) => $receipt->available_cents > 0)->count(),
                    'new_this_month' => $inMonth($client->created_at),
                    'next_due' => $pending->sortBy('due_date')->first()?->due_date,
                    'priority' => $priority,
                ];
            });
            $groups = [];
            foreach (($request->user()->managesDealership() ? ['person' => 'Clientes'] : ['person' => 'Clientes', 'dealership' => 'Concesionarios']) as $kind => $title) {
                $rows = $accounts->filter(fn ($account) => $account['client']->customer_kind === $kind);
                $debt = $rows->sum('debt_cents');
                $overdue = $rows->sum('overdue_cents');
                $attention = $rows->filter(fn ($account) => $account['debt_cents'] > 0 || $account['credit_cents'] > 0)->sort(function ($left, $right) {
                    return ($left['priority'] <=> $right['priority']) ?: ($right['debt_cents'] <=> $left['debt_cents']) ?: strcasecmp($left['client']->name, $right['client']->name);
                })->take(5)->values();
                $groups[$kind] = [
                    'title' => $title,
                    'list_route' => 'clients.index',
                    'create_route' => 'clients.create',
                    'route_parameters' => ['customer_kind' => $kind],
                    'total' => $rows->count(),
                    'active' => $rows->filter(fn ($account) => $account['client']->active)->count(),
                    'new_this_month' => $rows->where('new_this_month', true)->count(),
                    'with_debt' => $rows->where('debt_cents', '>', 0)->count(),
                    'with_credit' => $rows->where('credit_cents', '>', 0)->count(),
                    'with_overdue' => $rows->where('overdue_cents', '>', 0)->count(),
                    'debt_cents' => $debt,
                    'credit_cents' => $rows->sum('credit_cents'),
                    'overdue_cents' => $overdue,
                    'due_soon_cents' => $rows->sum('due_soon_cents'),
                    'applicable_cents' => $rows->sum('applicable_cents'),
                    'received_month_cents' => $rows->sum('received_month_cents'),
                    'sold_month_cents' => $rows->sum('sold_month_cents'),
                    'sales_month' => $rows->sum('sales_month'),
                    'open_orders' => $rows->sum('open_orders'),
                    'unapplied_receipts' => $rows->sum('unapplied_receipts'),
                    'overdue_percent' => $debt > 0 ? min(100, (int) round($overdue / $debt * 100)) : 0,
                    'attention' => $attention,
                    'account_ids' => [
                        'debt' => $rows->where('debt_cents', '>', 0)->pluck('client.id')->all(),
                        'overdue' => $rows->where('overdue_cents', '>', 0)->pluck('client.id')->all(),
                        'credit' => $rows->where('credit_cents', '>', 0)->pluck('client.id')->all(),
                        'due_soon' => $rows->where('due_soon_cents', '>', 0)->pluck('client.id')->all(),
                        'received' => $rows->where('received_month_cents', '>', 0)->pluck('client.id')->all(),
                        'sales' => $rows->where('sales_month', '>', 0)->pluck('client.id')->all(),
                    ],
                ];
            }

            return ['groups' => $groups, 'updatedAt' => $now, 'monthStart' => $monthStart, 'weekEnd' => $weekEnd];
        });
    }
}
