<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Queries\OrderQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(Request $request): RedirectResponse
    {
        return redirect()->route('workspace.sales', $request->only('dealership_id'));
    }

    public function collections(Request $r): View
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
