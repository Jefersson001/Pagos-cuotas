<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Order;
use App\Models\Receipt;
use App\Services\HeadOfficeOverviewService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class PortalController extends Controller
{
    public function headOffice(Request $request, HeadOfficeOverviewService $overview): View
    {
        return view('workspace.head-office', $overview->build($request));
    }

    private function client(Request $request): Client
    {
        abort_unless($request->user()->isCustomer(), 403);

        return Client::whereKey($request->user()->client_id)->where('dealership_id', $request->user()->dealership_id)->where('active', true)->firstOrFail();
    }

    public function account(Request $request): View
    {
        return DB::transaction(function () use ($request): View {
            $client = $this->client($request);
            $orders = $client->orders()->with(['payments', 'installments.allocations.payment'])->latest()->get();
            $receipts = $client->receipts()->with(['applications', 'method'])->latest()->get();

            return view('workspace.customer-account', compact('client', 'orders', 'receipts'));
        });
    }

    public function order(Request $request, Order $order): View
    {
        $client = $this->client($request);
        abort_unless($order->client_id === $client->id && $order->dealership_id === $client->dealership_id, 404);
        $order->load(['items', 'payments', 'installments.allocations.payment']);

        return view('workspace.customer-order', compact('order'));
    }

    public function receipt(Request $request, Receipt $receipt): View
    {
        $client = $this->client($request);
        abort_unless($receipt->client_id === $client->id && $receipt->dealership_id === $client->dealership_id, 404);
        $receipt->load(['method', 'applications.allocations.installment']);

        return view('workspace.customer-receipt', compact('receipt'));
    }
}
