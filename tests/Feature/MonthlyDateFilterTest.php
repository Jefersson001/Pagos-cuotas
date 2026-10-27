<?php

namespace Tests\Feature;

use App\Models\Receipt;
use App\Models\Role;
use App\Models\User;
use App\Services\SalesService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class MonthlyDateFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_lists_default_to_current_month_and_support_inclusive_custom_ranges(): void
    {
        $this->seed();
        $this->travelTo(now()->setDate(2026, 10, 27)->setTime(12, 0));
        $user = User::factory()->create(['role_id' => Role::where('name', 'user')->value('id')]);
        $rows = [];
        foreach (['2026-09-30 23:59:59', '2026-10-01 00:00:00', '2026-10-27 23:59:59', '2026-10-28 00:00:00'] as $date) {
            $receipt = Receipt::factory()->create(['user_id' => $user->id, 'created_at' => $date]);
            $order = app(SalesService::class)->create($user, ['token' => (string) Str::uuid(), 'client_id' => $receipt->client_id, 'description' => 'Moto', 'items' => [['description' => 'Moto', 'quantity' => 1, 'unit_price' => '400.00']], 'initial_amount' => '0', 'installment_count' => 4, 'frequency' => 'monthly', 'first_due' => '2026-11-01']);
            $order->update(['created_at' => $date]);
            $rows[] = ['orders' => $order->id, 'receipts' => $receipt->id];
        }

        foreach (['orders', 'receipts'] as $list) {
            $this->actingAs($user)->get(route($list.'.index'))->assertOk()
                ->assertSee('value="2026-10-01"', false)->assertSee('value="2026-10-27"', false)
                ->assertViewHas($list, fn ($items) => $items->total() === 2 && $items->pluck('id')->sort()->values()->all() === [$rows[1][$list], $rows[2][$list]]);
            $this->get(route($list.'.index', ['from' => '2026-09-30', 'to' => '2026-10-01']))->assertOk()
                ->assertViewHas($list, fn ($items) => $items->total() === 2 && $items->pluck('id')->sort()->values()->all() === [$rows[0][$list], $rows[1][$list]]);
        }
        $this->get(route('receipts.index'))->assertViewHas('received', 20000)->assertViewHas('credit', 20000);
    }

    public function test_automatic_month_rolls_forward_but_custom_dates_are_preserved_and_invalid_ranges_rejected(): void
    {
        $this->seed();
        $user = User::factory()->create(['role_id' => Role::where('name', 'user')->value('id')]);
        $this->actingAs($user);
        $this->travelTo(now()->setDate(2026, 11, 2)->setTime(12, 0));
        foreach (['orders', 'receipts'] as $list) {
            $this->get(route($list.'.index', ['date_mode' => 'month', 'from' => '2026-10-01', 'to' => '2026-10-27']))->assertOk()
                ->assertSee('value="2026-11-01"', false)->assertSee('value="2026-11-02"', false);
            $this->get(route($list.'.index', ['date_mode' => 'custom', 'from' => '2026-09-01', 'to' => '2026-10-15']))->assertOk()
                ->assertSee('value="2026-09-01"', false)->assertSee('value="2026-10-15"', false);
            $this->get(route($list.'.index', ['from' => '2026-11-02', 'to' => '2026-11-01']))->assertSessionHasErrors('to');
            $this->get(route($list.'.index', ['from' => 'invalid']))->assertSessionHasErrors('from');
        }
    }
}
