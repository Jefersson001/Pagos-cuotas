<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Order;
use App\Models\SalesEvent;
use App\Services\Audit;
use App\Services\DealershipContext;
use App\Support\MonthlyDateFilter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class SalesEventController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', SalesEvent::class);
        MonthlyDateFilter::apply($request);
        $canFilter = $request->user()->isAdmin() || $request->user()->isHeadOffice();
        $concessionaires = $canFilter ? Client::visibleTo($request->user())->where('customer_kind', 'dealership')->orderBy('name')->get(['id', 'name', 'dealership_id']) : collect();
        $request->validate(['q' => 'nullable|string|max:100', 'active' => 'nullable|in:0,1', 'concessionaire_id' => ['nullable', 'integer', Rule::in($concessionaires->pluck('id')->all())]]);
        $orders = $request->user()->isHeadOffice() ? Order::headOfficePortfolio($request->user()) : Order::visibleTo($request->user());
        $orders->where('cancelled', false)->whereDate('created_at', '>=', $request->input('from'))->whereDate('created_at', '<=', $request->input('to'));
        if ($canFilter && $request->filled('concessionaire_id')) {
            $orders->whereHas('client', fn (Builder $clients): Builder => $clients->where('concessionaire_id', $request->integer('concessionaire_id')));
        }
        $query = SalesEvent::visibleTo($request->user())->with('dealership')
            ->withCount(['orders as sales_count' => fn (Builder $query): Builder => $query->whereIn('orders.id', (clone $orders)->select('orders.id'))])
            ->withSum(['orders as sales_total' => fn (Builder $query): Builder => $query->whereIn('orders.id', (clone $orders)->select('orders.id'))], 'total');
        if ($request->filled('q')) {
            $query->where('name', 'like', '%'.$request->input('q').'%');
        }
        if ($request->filled('active')) {
            $query->where('active', $request->input('active'));
        }

        return view('sales-events.index', ['events' => $query->orderByDesc('sales_total')->orderByDesc('sales_count')->orderBy('name')->paginate(20)->withQueryString(), 'concessionaires' => $concessionaires]);
    }

    public function options(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasActiveAccess() && $request->user()->canOperateFinancial(), 403);
        $request->validate(['client_id' => 'required|integer']);
        $client = Client::visibleTo($request->user())->where('active', true)->whereHas('dealership', fn (Builder $query): Builder => $query->where('active', true))->findOrFail($request->integer('client_id'));
        SalesEvent::defaultForCompany($client->dealership_id);
        $events = SalesEvent::where('dealership_id', $client->dealership_id)->where('active', true)->orderByDesc('is_default')->orderBy('name')->get(['id', 'name', 'is_default']);

        return response()->json(['events' => $events, 'default_id' => $events->firstWhere('is_default', true)?->id])->header('Cache-Control', 'private, no-store');
    }

    public function create(): View
    {
        Gate::authorize('create', SalesEvent::class);

        return view('sales-events.form', ['event' => new SalesEvent(['active' => true, 'is_default' => false])]);
    }

    public function show(Request $request, SalesEvent $salesEvent): View
    {
        Gate::authorize('view', $salesEvent);
        $request->session()->forget('_old_input');

        return view('sales-events.form', ['event' => $salesEvent, 'readOnly' => true]);
    }

    public function edit(SalesEvent $salesEvent): View
    {
        Gate::authorize('update', $salesEvent);

        return view('sales-events.form', ['event' => $salesEvent]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('create', SalesEvent::class);

        return $this->save($request, new SalesEvent);
    }

    public function update(Request $request, SalesEvent $salesEvent): RedirectResponse
    {
        Gate::authorize('update', $salesEvent);

        return $this->save($request, $salesEvent);
    }

    private function save(Request $request, SalesEvent $event): RedirectResponse
    {
        $companyId = DealershipContext::forContactWrite($request->user(), $request->filled('dealership_id') ? $request->integer('dealership_id') : null, $event->exists ? $event->dealership_id : null);
        $values = $request->validate(['name' => ['required', 'string', 'max:100', Rule::unique('sales_events')->where('dealership_id', $companyId)->ignore($event->id)], 'active' => 'required|boolean', 'is_default' => 'sometimes|boolean']);
        $values['dealership_id'] = $companyId;
        $values['is_default'] = $request->boolean('is_default');
        DB::transaction(function () use ($request, $event, $values): void {
            DealershipContext::forContactWrite($request->user(), null, $values['dealership_id']);
            if ($event->exists) {
                $event->setRawAttributes(SalesEvent::lockForUpdate()->findOrFail($event->id)->getAttributes(), true);
                Gate::authorize('update', $event);
            }
            if (($event->is_default && ! $values['is_default']) || ($values['is_default'] && ! $values['active'])) {
                throw ValidationException::withMessages(['is_default' => 'Debe existir una jornada normal activa. Para cambiarla, marca primero otra jornada como predeterminada.']);
            }
            $before = $event->getAttributes();
            if ($values['is_default']) {
                SalesEvent::where('dealership_id', $values['dealership_id'])->where('is_default', true)->when($event->exists, fn (Builder $query): Builder => $query->whereKeyNot($event->id))->update(['is_default' => false]);
            } else {
                SalesEvent::defaultForCompany($values['dealership_id']);
            }
            if (SalesEvent::where('dealership_id', $values['dealership_id'])->where('name', $values['name'])->when($event->exists, fn (Builder $query): Builder => $query->whereKeyNot($event->id))->exists()) {
                throw ValidationException::withMessages(['name' => 'Ya existe una jornada con este nombre en la empresa.']);
            }
            $event->fill($values)->save();
            Audit::log($request->user()->id, 'save', 'sales_event', $event->id, ['before' => $before, 'after' => $event->getAttributes()], $event->dealership_id);
        }, 3);

        return redirect()->route('sales-events.show', $event)->with('success', 'Jornada de venta guardada.');
    }

    public function destroy(Request $request, SalesEvent $salesEvent): RedirectResponse
    {
        Gate::authorize('delete', $salesEvent);
        DB::transaction(function () use ($request, $salesEvent): void {
            DealershipContext::forContactWrite($request->user(), null, $salesEvent->dealership_id);
            $event = SalesEvent::lockForUpdate()->findOrFail($salesEvent->id);
            Gate::authorize('delete', $event);
            if ($event->is_default || $event->orders()->exists()) {
                throw ValidationException::withMessages(['event' => 'La jornada normal y las jornadas con ventas deben conservarse. Puedes desactivar una jornada especial para dejar de seleccionarla.']);
            }
            $before = $event->getAttributes();
            $event->delete();
            Audit::log($request->user()->id, 'delete', 'sales_event', $event->id, $before, $event->dealership_id);
        }, 3);

        return redirect()->route('sales-events.index')->with('success', 'Jornada de venta eliminada.');
    }
}
