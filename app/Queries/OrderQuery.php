<?php

namespace App\Queries;

use App\Models\Order;
use Illuminate\Http\Request;

class OrderQuery
{
    public static function get(Request $r)
    {
        $q = Order::visibleTo($r->user())->with(['dealership', 'client.owner', 'owner', 'payments.method', 'payments.recorder', 'installments.allocations.payment']);
        if ($r->filled('user_id') && $r->user()->canManageUsers()) {
            $q->where('user_id', $r->integer('user_id'));
        }
        if ($r->filled('client')) {
            $q->whereHas('client', fn ($q) => $q->where('name', 'like', '%'.$r->string('client')->toString().'%'));
        }
        if ($r->filled('from')) {
            $q->whereDate('created_at', '>=', $r->input('from'));
        }
        if ($r->filled('to')) {
            $q->whereDate('created_at', '<=', $r->input('to'));
        }
        $orders = $q->latest()->get();

        return $r->filled('status') ? $orders->filter(fn ($o) => $o->status === $r->input('status')) : $orders;
    }
}
