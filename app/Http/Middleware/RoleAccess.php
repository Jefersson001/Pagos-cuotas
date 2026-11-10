<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RoleAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user->isCustomer()) {
            abort_unless($request->routeIs('home', 'portal.*', 'logout'), 403);
        } elseif ($user->isHeadOffice()) {
            abort_unless($request->routeIs('home', 'head-office.*', 'clients.*', 'users', 'users.*', 'reports', 'workspace.settings', 'receipts.index', 'receipts.show', 'receipts.approve', 'receipts.reject', 'receipts.void', 'logout'), 403);
        }

        return $next($request);
    }
}
