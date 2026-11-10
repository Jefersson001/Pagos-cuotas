<?php

namespace App\Http\Controllers;

use App\Services\CustomerOverviewService;
use App\Services\ProductOverviewService;
use App\Services\SalesOverviewService;
use App\Services\SettingsOverviewService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class WorkspaceController extends Controller
{
    public function index(Request $request): View|RedirectResponse
    {
        if ($request->user()->isCustomer()) {
            return redirect()->route('portal.account');
        }

        return view('workspace.home', ['modules' => $request->user()->workspaceModules()]);
    }

    public function customers(Request $request, CustomerOverviewService $overview): View
    {
        return view('workspace.customers', $overview->build($request));
    }

    public function products(Request $request, ProductOverviewService $overview): View
    {
        return view('workspace.products', $overview->build($request));
    }

    public function sales(Request $request, SalesOverviewService $overview): View
    {
        return view('workspace.sales', $overview->build($request));
    }

    public function settings(Request $request, SettingsOverviewService $overview): View
    {
        return view('workspace.settings', $overview->build($request));
    }
}
