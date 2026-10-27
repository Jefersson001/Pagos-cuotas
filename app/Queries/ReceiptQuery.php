<?php

namespace App\Queries;

use App\Models\Receipt;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class ReceiptQuery
{
    public static function get(Request $r): Collection
    {
        $q = Receipt::visibleTo($r->user())->with(['client', 'dealership', 'recorder', 'method', 'applications']);
        if ($r->filled('user_id') && $r->user()->canManageUsers()) {
            $q->where('user_id', $r->integer('user_id'));
        }
        if ($r->filled('client')) {
            $q->whereHas('client', fn ($c) => $c->where('name', 'like', '%'.$r->input('client').'%'));
        }
        if ($r->filled('from')) {
            $q->whereDate('created_at', '>=', $r->input('from'));
        }
        if ($r->filled('to')) {
            $q->whereDate('created_at', '<=', $r->input('to'));
        }

        return $q->latest()->get();
    }
}
