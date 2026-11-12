<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\PaymentMethod;
use App\Models\Receipt;
use App\Models\Remittance;
use App\Services\RemittanceService;
use App\Support\Money;
use App\Support\MonthlyDateFilter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RemittanceController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', Remittance::class);
        MonthlyDateFilter::apply($request);
        $request->validate(['status' => 'nullable|in:pending,approved,rejected,void', 'concessionaire_id' => 'nullable|integer']);
        $query = Remittance::visibleTo($request->user())->with(['concessionaire', 'recorder', 'method'])->whereDate('payment_date', '>=', $request->input('from'))->whereDate('payment_date', '<=', $request->input('to'));
        $cash = Receipt::visibleTo($request->user())->where('collection_channel', 'concessionaire')->whereNull('voided_at');
        if ($request->filled('concessionaire_id')) {
            $query->where('concessionaire_id', $request->integer('concessionaire_id'));
            $cash->where('collecting_concessionaire_id', $request->integer('concessionaire_id'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        $heldCents = (int) (clone $cash)->where('approval_status', 'pending')->whereNull('remittance_id')->get()->sum(fn (Receipt $r): int => Money::cents($r->amount));
        $sentCents = (int) (clone $cash)->where('approval_status', 'pending')->whereHas('remittance', fn ($q) => $q->where('status', 'pending'))->get()->sum(fn (Receipt $r): int => Money::cents($r->amount));

        return view('remittances.index', ['remittances' => $query->latest()->paginate(20)->withQueryString(), 'concessionaires' => $this->branches($request), 'heldCents' => $heldCents, 'sentCents' => $sentCents]);
    }

    public function create(Request $request): View
    {
        Gate::authorize('create', Remittance::class);
        $request->validate(['concessionaire_id' => 'nullable|integer']);
        $branches = $this->branches($request);
        $branch = $request->filled('concessionaire_id') ? $branches->firstWhere('id', $request->integer('concessionaire_id')) : $branches->first();
        abort_if($request->filled('concessionaire_id') && ! $branch, 403);
        $receipts = $branch ? Receipt::visibleTo($request->user())->where('collection_channel', 'concessionaire')->where('collecting_concessionaire_id', $branch->id)->where('approval_status', 'pending')->whereNull('voided_at')->whereNull('remittance_id')->with(['client', 'sourceOrder', 'intendedInstallment'])->orderBy('payment_date')->orderBy('id')->get() : collect();

        return view('remittances.create', ['concessionaires' => $branches, 'branch' => $branch, 'receipts' => $receipts, 'methods' => $branch ? PaymentMethod::where('dealership_id', $branch->dealership_id)->where('active', true)->orderBy('name')->get() : collect()]);
    }

    public function store(Request $request, RemittanceService $service): RedirectResponse
    {
        Gate::authorize('create', Remittance::class);
        $values = $request->validate(['token' => 'required|uuid', 'concessionaire_id' => 'required|integer', 'payment_method_id' => 'required|integer', 'receipt_ids' => 'required|array|min:1|max:100', 'receipt_ids.*' => 'integer|distinct', 'amount' => 'required|string', 'reference' => 'required|string|max:255', 'payment_date' => 'required|date_format:Y-m-d|before_or_equal:today', 'proof' => 'required|file|mimes:jpg,jpeg,png,pdf|max:5120']);
        $remittance = $service->create($request->user(), $values, $request->file('proof'));

        return redirect()->route('remittances.show', $remittance)->with('success', 'Remesa enviada. Los cobros conservan su detalle y esperan aprobación de casa matriz.');
    }

    public function show(Remittance $remittance, RemittanceService $service): View
    {
        Gate::authorize('view', $remittance);
        $remittance->load(['concessionaire', 'recorder', 'approver', 'rejecter', 'method', 'receipts.client', 'receipts.intendedInstallment', 'receipts.applications', 'receipts.sourceOrder.payments', 'receipts.sourceOrder.installments.allocations.payment']);
        $previews = $service->preview($remittance);

        return view('remittances.show', compact('remittance', 'previews'));
    }

    public function approve(Request $request, Remittance $remittance, RemittanceService $service): RedirectResponse
    {
        Gate::authorize('review', $remittance);
        $values = $request->validate(['approval_proof' => 'required|file|mimes:jpg,jpeg,png,pdf|max:5120', 'expected_applied_cents' => 'required|array', 'expected_applied_cents.*' => 'required|integer|min:0']);
        $service->approve($request->user(), $remittance, $request->file('approval_proof'), $values['expected_applied_cents']);

        return back()->with('success', 'Remesa aprobada. Se confirmaron sus cobros y se aplicaron a sus ventas, sin registrar un segundo ingreso.');
    }

    public function reject(Request $request, Remittance $remittance, RemittanceService $service): RedirectResponse
    {
        Gate::authorize('review', $remittance);
        $values = $request->validate(['reason' => 'required|string|min:10|max:1000']);
        $service->reject($request->user(), $remittance, $values['reason']);

        return back()->with('success', 'Remesa rechazada. Sus cobros siguen reportados y quedan disponibles para corregir o reenviar.');
    }

    public function void(Request $request, Remittance $remittance, RemittanceService $service): RedirectResponse
    {
        Gate::authorize('review', $remittance);
        $values = $request->validate(['reason' => 'required|string|min:10|max:1000']);
        $service->void($request->user(), $remittance, $values['reason']);

        return back()->with('success', 'Remesa anulada. Se revirtieron sus cobros y aplicaciones; se conserva el historial.');
    }

    public function proof(Request $request, Remittance $remittance): StreamedResponse
    {
        Gate::authorize('view', $remittance);
        $request->validate(['side' => 'nullable|in:sender,approval']);
        $path = $request->input('side') === 'approval' ? $remittance->approval_proof_path : $remittance->proof_path;
        abort_unless($path && Storage::disk('local')->exists($path), 404);
        $name = 'REM-'.$remittance->id.($request->input('side') === 'approval' ? '-casa-matriz' : '-concesionario').'.'.pathinfo($path, PATHINFO_EXTENSION);
        $headers = ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff'];

        return $request->boolean('preview') ? Storage::disk('local')->response($path, $name, $headers, 'inline') : Storage::disk('local')->download($path, $name, $headers);
    }

    private function branches(Request $request): Collection
    {
        if ($request->user()->isAdmin() || $request->user()->isHeadOffice()) {
            return Client::visibleTo($request->user())->where('customer_kind', 'dealership')->orderBy('name')->get();
        }

        return Client::whereKey($request->user()->concessionaire_id)->where('dealership_id', $request->user()->dealership_id)->where('customer_kind', 'dealership')->get();
    }
}
