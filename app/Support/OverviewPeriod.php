<?php

namespace App\Support;

use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class OverviewPeriod
{
    public function __construct(public Carbon $from, public Carbon $to, public bool $custom) {}

    public static function fromRequest(Request $request): self
    {
        MonthlyDateFilter::apply($request);
        $request->validate(['from' => 'before_or_equal:today', 'to' => 'before_or_equal:today']);

        return new self(Carbon::parse($request->input('from'))->startOfDay(), Carbon::parse($request->input('to'))->endOfDay()->min(now()), $request->input('date_mode') === 'custom');
    }

    public function contains(?CarbonInterface $date): bool
    {
        return $date !== null && $date->betweenIncluded($this->from, $this->to);
    }

    /** @return Collection<int, array{from: Carbon, to: Carbon, label: string}> */
    public function buckets(): Collection
    {
        $days = (int) $this->from->diffInDays($this->to->copy()->startOfDay()) + 1;
        $step = max(7, (int) ceil($days / 8));
        $format = $this->from->year === $this->to->year ? 'd/m' : 'd/m/Y';

        return collect(range(0, (int) ceil($days / $step) - 1))->map(function (int $index) use ($step, $format): array {
            $from = $this->from->copy()->addDays($index * $step);
            $to = $from->copy()->addDays($step - 1)->endOfDay()->min($this->to);

            return ['from' => $from, 'to' => $to, 'label' => $from->isSameDay($to) ? $from->format($format) : $from->format($format).'–'.$to->format($format)];
        });
    }
}
