<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Receipt;
use App\Services\CustomerOverviewService;
use App\Services\ProductOverviewService;
use App\Services\SalesOverviewService;
use App\Services\SettingsOverviewService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class WorkspaceController extends Controller
{
    public function index(Request $request): View|RedirectResponse
    {
        if ($request->user()->isCustomer()) {
            return redirect()->route('portal.account');
        }

        $pendingPayments = $request->user()->isHeadOffice()
            ? Receipt::visibleTo($request->user())->where('approval_status', 'pending')->whereNull('voided_at')->where(fn ($query) => $query->where('collection_channel', 'head_office')->orWhereNotNull('remittance_id'))->count()
            : 0;

        return view('workspace.home', ['modules' => $request->user()->workspaceModules(), 'pendingPayments' => $pendingPayments]);
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

    public function headOfficeSales(Request $request, SalesOverviewService $overview): View
    {
        abort_unless($request->user()->isHeadOffice(), 403);
        $request->validate(['concessionaire_id' => ['nullable', 'integer', Rule::exists('clients', 'id')->where('customer_kind', 'dealership')->where('dealership_id', $request->user()->dealership_id)]]);

        return view('workspace.sales', $overview->build($request) + [
            'readOnlyPortfolio' => true,
            'concessionaires' => Client::where('dealership_id', $request->user()->dealership_id)->where('customer_kind', 'dealership')->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function settings(Request $request, SettingsOverviewService $overview): View
    {
        return view('workspace.settings', $overview->build($request));
    }
}
