<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Receipt;
use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SalesOverviewService
{
    /** @return array<string, mixed> */
    public function build(Request $request): array
    {
        return DB::transaction(function () use ($request): array {
            $now = now();
            $today = $now->copy()->startOfDay();
            $monthStart = $now->copy()->startOfMonth();
            $weekEnd = $today->copy()->addDays(6);
            $previousStart = $monthStart->copy()->subMonthNoOverflow();
            $previousEnd = $previousStart->copy()->day(min($now->day, $previousStart->daysInMonth))->setTime($now->hour, $now->minute, $now->second);
            $inMonth = fn (CarbonInterface $date): bool => $date->betweenIncluded($monthStart, $now);
            $clientQuery = $request->user()->isHeadOffice()
                ? Client::headOfficeCustomers($request->user())->with('concessionaire')
                : Client::visibleTo($request->user());
            if ($request->user()->isHeadOffice() && $request->filled('concessionaire_id')) {
                $clientQuery->where('concessionaire_id', $request->integer('concessionaire_id'));
            }
            $clients = $clientQuery->get();
            $orderQuery = $request->user()->isHeadOffice() ? Order::headOfficePortfolio($request->user()) : Order::visibleTo($request->user());
            $orders = $orderQuery->whereIn('client_id', $clients->modelKeys())->where('created_at', '<=', $now)
                ->with(['client', 'payments.allocations', 'installments.allocations.payment'])->get();
            $receipts = Receipt::visibleTo($request->user())->whereIn('client_id', $clients->modelKeys())->where('created_at', '<=', $now)
                ->with(['client', 'method', 'applications'])->get();
            $validOrders = $orders->where('cancelled', false);
            $pendingOrders = $validOrders->filter(fn (Order $order): bool => $order->balance_cents > 0);
            $validReceipts = $receipts->whereNull('voided_at')->where('approval_status', 'approved');
            $monthOrders = $validOrders->filter(fn (Order $order): bool => $inMonth($order->created_at));
            $monthReceipts = $validReceipts->filter(fn (Receipt $receipt): bool => $inMonth($receipt->created_at));
            $monthApplications = $validOrders->flatMap->payments->whereNull('voided_at')->filter(fn (Payment $payment): bool => $inMonth($payment->created_at));
            $pending = $pendingOrders->flatMap(fn (Order $order) => $order->installments->filter(fn ($installment): bool => $installment->remaining_cents > 0)
                ->map(fn ($installment): array => ['order' => $order, 'installment' => $installment]));
            $overdue = $pending->filter(fn (array $row): bool => $row['installment']->due_date->lt($today));
            $dueSoon = $pending->filter(fn (array $row): bool => $row['installment']->due_date->betweenIncluded($today, $weekEnd));
            $initials = $pending->filter(fn (array $row): bool => $row['installment']->number === 0);
            $debt = (int) $pendingOrders->sum('balance_cents');
            $overdueAmount = (int) $overdue->sum(fn (array $row): int => $row['installment']->remaining_cents);
            $aging = collect([
                ['label' => '1–30 días', 'from' => 1, 'to' => 30],
                ['label' => '31–60 días', 'from' => 31, 'to' => 60],
                ['label' => '61–90 días', 'from' => 61, 'to' => 90],
                ['label' => 'Más de 90 días', 'from' => 91, 'to' => PHP_INT_MAX],
            ])->map(function (array $bucket) use ($overdue, $today, $overdueAmount): array {
                $rows = $overdue->filter(function (array $row) use ($bucket, $today): bool {
                    $days = (int) $row['installment']->due_date->diffInDays($today);

                    return $days >= $bucket['from'] && $days <= $bucket['to'];
                });
                $amount = (int) $rows->sum(fn (array $row): int => $row['installment']->remaining_cents);

                return ['label' => $bucket['label'], 'amount_cents' => $amount, 'count' => $rows->count(), 'percent' => $overdueAmount > 0 ? round($amount / $overdueAmount * 100, 1) : 0];
            });
            $overdueByClient = $overdue->groupBy(fn (array $row): int => $row['order']->client_id);
            $debtByClient = $pendingOrders->groupBy('client_id')->map(fn ($sales): int => (int) $sales->sum('balance_cents'));
            $creditByClient = $validReceipts->groupBy('client_id')->map(fn ($money): int => (int) $money->sum('available_cents'));
            $accounts = $clients->map(function (Client $client) use ($debtByClient, $creditByClient, $overdueByClient, $today): array {
                $debt = $debtByClient->get($client->id, 0);
                $credit = $creditByClient->get($client->id, 0);

                $late = $overdueByClient->get($client->id, collect());
                $oldest = $late->sortBy(fn (array $row): string => $row['installment']->due_date->toDateString())->first();

                return [
                    'client' => $client, 'debt_cents' => $debt, 'credit_cents' => $credit, 'applicable_cents' => min($debt, $credit),
                    'overdue_cents' => (int) $late->sum(fn (array $row): int => $row['installment']->remaining_cents),
                    'days_overdue' => $oldest ? (int) $oldest['installment']->due_date->diffInDays($today) : 0,
                ];
            });
            $weeks = collect(range(0, intdiv($now->day - 1, 7)))->map(function (int $index) use ($now, $monthStart, $monthOrders, $monthReceipts): array {
                $from = $monthStart->copy()->addDays($index * 7);
                $to = $from->copy()->addDays(6)->endOfDay()->min($now);
                $inWeek = fn (CarbonInterface $date): bool => $date->betweenIncluded($from, $to);

                return [
                    'label' => $from->isSameDay($to) ? $from->format('d/m') : $from->format('d').'–'.$to->format('d/m'),
                    'sold_cents' => (int) $monthOrders->filter(fn (Order $order): bool => $inWeek($order->created_at))->sum(fn (Order $order): int => Money::cents($order->total)),
                    'received_cents' => (int) $monthReceipts->filter(fn (Receipt $receipt): bool => $inWeek($receipt->created_at))->sum(fn (Receipt $receipt): int => Money::cents($receipt->amount)),
                ];
            });
            $methods = $monthReceipts->groupBy('payment_method_id')->map(fn ($money): array => [
                'name' => $money->first()->method?->name ?? 'Sin método', 'count' => $money->count(),
                'amount_cents' => (int) $money->sum(fn (Receipt $receipt): int => Money::cents($receipt->amount)),
            ])->sortByDesc('amount_cents')->values();
            $receiptMap = $validReceipts->keyBy('id');
            $inPrevious = fn (CarbonInterface $date): bool => $date->betweenIncluded($previousStart, $previousEnd);
            $previousSold = (int) $validOrders->filter(fn (Order $order): bool => $inPrevious($order->created_at))->sum(fn (Order $order): int => Money::cents($order->total));
            $previousReceived = (int) $validReceipts->filter(fn (Receipt $receipt): bool => $inPrevious($receipt->created_at))->sum(fn (Receipt $receipt): int => Money::cents($receipt->amount));
            $comparison = collect([
                'sales' => ['label' => 'Ventas confirmadas', 'current_cents' => (int) $monthOrders->sum(fn (Order $order): int => Money::cents($order->total)), 'previous_cents' => $previousSold],
                'receipts' => ['label' => 'Dinero recibido', 'current_cents' => (int) $monthReceipts->sum(fn (Receipt $receipt): int => Money::cents($receipt->amount)), 'previous_cents' => $previousReceived],
            ])->map(function (array $row): array {
                $difference = $row['current_cents'] - $row['previous_cents'];

                return $row + ['difference_cents' => $difference, 'change_percent' => $row['previous_cents'] > 0 ? round($difference / $row['previous_cents'] * 100, 1) : ($row['current_cents'] === 0 ? 0.0 : null)];
            });
            $legacyInitials = $pendingOrders->filter(fn (Order $order): bool => ! $order->installments->contains(fn ($item): bool => $item->number === 0))->map(function (Order $order): int {
                $paid = $order->payments->whereNull('voided_at')->sum(fn (Payment $payment): int => $payment->is_initial ? Money::cents($payment->amount) : Money::cents($payment->amount) - $payment->allocations->sum(fn ($allocation): int => Money::cents($allocation->amount)));

                return max(0, Money::cents($order->initial_amount) - $paid);
            })->filter(fn (int $amount): bool => $amount > 0);

            return [
                'clientCount' => $clients->count(),
                'aging' => $aging, 'previousStart' => $previousStart, 'previousEnd' => $previousEnd, 'comparison' => $comparison,
                'topDebtors' => $accounts->where('debt_cents', '>', 0)->sort(fn (array $left, array $right): int => ($right['overdue_cents'] <=> $left['overdue_cents']) ?: ($right['debt_cents'] <=> $left['debt_cents']) ?: ($right['days_overdue'] <=> $left['days_overdue']) ?: strcasecmp($left['client']->name, $right['client']->name))->take(5)->values(),
                'updatedAt' => $now, 'monthStart' => $monthStart, 'weekEnd' => $weekEnd,
                'salesMonth' => $monthOrders->count(), 'soldMonthCents' => (int) $monthOrders->sum(fn (Order $order): int => Money::cents($order->total)),
                'receiptsMonth' => $monthReceipts->count(), 'receivedMonthCents' => (int) $monthReceipts->sum(fn (Receipt $receipt): int => Money::cents($receipt->amount)),
                'appliedMonthCents' => (int) $monthApplications->sum(fn (Payment $payment): int => Money::cents($payment->amount)),
                'oldCreditAppliedCents' => (int) $monthApplications->filter(fn (Payment $payment): bool => $receiptMap->get($payment->receipt_id)?->created_at->lt($monthStart) ?? false)->sum(fn (Payment $payment): int => Money::cents($payment->amount)),
                'debtCents' => $debt, 'debtClients' => $debtByClient->count(), 'openOrders' => $pendingOrders->count(),
                'overdueCents' => $overdueAmount, 'overdueCount' => $overdue->count(),
                'overduePercent' => $debt > 0 ? min(100, (int) round($overdueAmount / $debt * 100)) : 0,
                'dueSoonCents' => (int) $dueSoon->sum(fn (array $row): int => $row['installment']->remaining_cents), 'dueSoonCount' => $dueSoon->count(),
                'initialCents' => (int) $initials->sum(fn (array $row): int => $row['installment']->remaining_cents) + (int) $legacyInitials->sum(), 'initialCount' => $initials->count() + $legacyInitials->count(),
                'creditCents' => (int) $validReceipts->sum('available_cents'), 'creditClients' => $creditByClient->filter(fn (int $credit): bool => $credit > 0)->count(),
                'applicableCents' => (int) $accounts->sum('applicable_cents'),
                'creditAccounts' => $accounts->where('applicable_cents', '>', 0)->sortByDesc('applicable_cents')->take(4)->values(),
                'receiptStates' => $validReceipts->countBy(fn (Receipt $receipt): string => $receipt->status),
                'orderStates' => $orders->countBy(fn (Order $order): string => $order->status),
                'dueItems' => $pending->filter(fn (array $row): bool => $row['installment']->due_date->lte($weekEnd))->sortBy(fn (array $row): string => $row['installment']->due_date->toDateString())->take(6)->values(),
                'recentReceipts' => $monthReceipts->sort(fn (Receipt $left, Receipt $right): int => ($right->created_at <=> $left->created_at) ?: ($right->id <=> $left->id))->take(5)->values(),
                'weeks' => $weeks, 'maxWeekCents' => max(1, $weeks->max('sold_cents') ?? 0, $weeks->max('received_cents') ?? 0),
                'methods' => $methods, 'maxMethodCents' => max(1, $methods->max('amount_cents') ?? 0),
            ];
        });
    }
}
