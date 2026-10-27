<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class MonthlyDateFilter
{
    public static function apply(Request $request): void
    {
        $request->validate(['from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d', 'date_mode' => 'nullable|in:month,custom']);
        $automatic = $request->input('date_mode') === 'month' || (! $request->filled('from') && ! $request->filled('to'));
        $from = $automatic ? today()->startOfMonth()->toDateString() : ($request->input('from') ?: today()->startOfMonth()->toDateString());
        $to = $automatic ? today()->toDateString() : ($request->input('to') ?: today()->toDateString());
        if ($to < $from) {
            throw ValidationException::withMessages(['to' => 'La fecha Hasta debe ser igual o posterior a Desde.']);
        }
        $request->merge(['from' => $from, 'to' => $to, 'date_mode' => $automatic ? 'month' : 'custom']);
    }
}
