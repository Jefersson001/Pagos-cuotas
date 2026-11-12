<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Order;
use App\Models\Receipt;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

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
            $actor = $request->user();
            $canFilterConcessionaires = $actor->isAdmin() || $actor->isHeadOffice();
            $concessionaires = $canFilterConcessionaires
                ? Client::visibleTo($actor)->where('customer_kind', 'dealership')->orderBy('name')->get()
                : collect();
            if ($canFilterConcessionaires) {
                $request->validate(['concessionaire_id' => ['nullable', 'integer', Rule::in($concessionaires->modelKeys())]]);
            }
            $selectedConcessionaire = $canFilterConcessionaires && $request->filled('concessionaire_id')
                ? $concessionaires->firstWhere('id', $request->integer('concessionaire_id')) : null;
            $clients = $actor->isHeadOffice()
                ? Client::headOfficeCustomers($actor)->get()->merge($concessionaires)
                : Client::visibleTo($actor)->get();
            if ($selectedConcessionaire) {
                $clients = $clients->filter(fn (Client $client): bool => $client->id === $selectedConcessionaire->id
                    || ($client->customer_kind === 'person' && $client->concessionaire_id === $selectedConcessionaire->id && $client->dealership_id === $selectedConcessionaire->dealership_id));
            }
            $ids = $clients->modelKeys();
            $orderQuery = $actor->isHeadOffice() ? Order::headOfficePortfolio($actor) : Order::visibleTo($actor);
            $orders = $orderQuery->whereIn('client_id', $ids)->where('created_at', '<=', $now)->with(['payments', 'installments.allocations.payment'])->get()->groupBy('client_id');
            $receipts = Receipt::visibleTo($actor)->whereIn('client_id', $ids)->where('created_at', '<=', $now)->where('approval_status', 'approved')->whereNull('voided_at')->with('applications')->get()->groupBy('client_id');
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
            if ($canFilterConcessionaires) {
                $people = $accounts->filter(fn (array $account): bool => $account['client']->customer_kind === 'person');
                $accounts = $accounts->map(function (array $account) use ($people): array {
                    $dealer = $account['client'];
                    if ($dealer->customer_kind !== 'dealership') {
                        return $account;
                    }
                    $members = $people->filter(fn (array $person): bool => $person['client']->concessionaire_id === $dealer->id
                        && $person['client']->dealership_id === $dealer->dealership_id)->push($account);
                    foreach (['debt_cents', 'credit_cents', 'overdue_cents', 'due_soon_cents', 'applicable_cents', 'received_month_cents', 'sold_month_cents', 'sales_month', 'open_orders', 'unapplied_receipts'] as $metric) {
                        $account[$metric] = $members->sum($metric);
                    }
                    $account['next_due'] = $members->pluck('next_due')->filter()->sort()->first();
                    $account['priority'] = match (true) {
                        $account['overdue_cents'] > 0 => 0,
                        $account['debt_cents'] > 0 && $account['credit_cents'] > 0 => 1,
                        $account['debt_cents'] > 0 => 2,
                        $account['credit_cents'] > 0 => 3,
                        default => 4,
                    };

                    return $account;
                });
            }
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
                    'list_route' => $actor->isHeadOffice() && $kind === 'person' ? 'head-office.clients' : 'clients.index',
                    'create_route' => $actor->isHeadOffice() && $kind === 'person' ? null : 'clients.create',
                    'show_route' => $actor->isHeadOffice() && $kind === 'person' ? 'head-office.clients.show' : 'clients.show',
                    'aggregate' => $canFilterConcessionaires && $kind === 'dealership',
                    'route_parameters' => array_filter(['customer_kind' => $kind, 'concessionaire_id' => $selectedConcessionaire?->id, 'dealership_id' => $actor->isAdmin() ? DealershipContext::id($actor) : null], fn ($value): bool => $value !== null),
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
                        'all' => $rows->pluck('client.id')->all(),
                        'debt' => $rows->where('debt_cents', '>', 0)->pluck('client.id')->all(),
                        'overdue' => $rows->where('overdue_cents', '>', 0)->pluck('client.id')->all(),
                        'credit' => $rows->where('credit_cents', '>', 0)->pluck('client.id')->all(),
                        'due_soon' => $rows->where('due_soon_cents', '>', 0)->pluck('client.id')->all(),
                        'received' => $rows->where('received_month_cents', '>', 0)->pluck('client.id')->all(),
                        'sales' => $rows->where('sales_month', '>', 0)->pluck('client.id')->all(),
                    ],
                ];
            }

            return ['groups' => $groups, 'updatedAt' => $now, 'monthStart' => $monthStart, 'weekEnd' => $weekEnd,
                'concessionaires' => $concessionaires, 'canFilterConcessionaires' => $canFilterConcessionaires,
                'selectedConcessionaire' => $selectedConcessionaire];
        });
    }
}
