<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Queries\OrderQuery;
use App\Services\DashboardService;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(Request $r, DashboardService $service): View
    {
        $r->validate(['from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d', 'date_mode' => 'nullable|in:today,custom', 'page' => 'nullable|integer|min:1']);
        $today = today()->toDateString();
        $automatic = $r->input('date_mode') === 'today' || (! $r->filled('from') && ! $r->filled('to'));
        $from = $automatic ? $today : ($r->input('from') ?: $today);
        $to = $automatic ? $today : ($r->input('to') ?: $today);
        if ($to < $from) {
            throw ValidationException::withMessages(['to' => 'La fecha Hasta debe ser igual o posterior a Desde.']);
        }
        $r->query->remove('q');
        $r->query->remove('account_state');
        $r->merge(['from' => $from, 'to' => $to, 'date_mode' => $automatic ? 'today' : 'custom']);

        return view('dashboard', $service->build($r));
    }

    public function collections(Request $r)
    {
        $r->validate(['from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d']);
        $copy = $r->duplicate();
        $copy->query->remove('from');
        $copy->query->remove('to');
        $orders = OrderQuery::get($copy);
        $rows = $orders->where('cancelled', false)->flatMap(fn ($o) => $o->installments->map(function ($i) use ($o) {
            $i->setRelation('order', $o);

            return $i;
        }))->sortBy('due_date');
        $filter = $r->input('filter', 'pending');
        $rows = $rows->filter(function ($i) use ($r, $filter) {
            if ($r->filled('from') && $i->due_date->toDateString() < $r->input('from')) {
                return false;
            }if ($r->filled('to') && $i->due_date->toDateString() > $r->input('to')) {
                return false;
            }

            return match ($filter) {
                'completed' => $i->order->status === 'Pagado','paid' => $i->remaining_cents === 0,'today' => $i->remaining_cents > 0 && $i->due_date->isToday(),'week' => $i->remaining_cents > 0 && $i->due_date->betweenIncluded(today()->startOfWeek(), today()->endOfWeek()),'overdue' => $i->remaining_cents > 0 && $i->due_date->lt(today()),'current' => $i->remaining_cents > 0 && $i->due_date->gte(today()),default => $i->remaining_cents > 0
            };
        });

        $rows = new LengthAwarePaginator($rows->values()->forPage(max(1, $r->integer('page', 1)), 20), $rows->count(), 20, max(1, $r->integer('page', 1)), ['path' => $r->url(), 'query' => $r->query()]);

        return view('collections', ['rows' => $rows, 'users' => $r->user()->canManageUsers() ? User::managedBy($r->user())->get() : collect()]);
    }
}
