<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

final class Money
{
    public static function cents(mixed $value): int
    {
        $s = (string) $value;
        if (! preg_match('/^\d{1,10}(?:\.\d{1,2})?$/D', $s)) {
            throw ValidationException::withMessages(['amount' => 'Importe inválido. Use máximo dos decimales y punto decimal.']);
        }
        [$whole,$fraction] = array_pad(explode('.', $s), 2, '');

        return ((int) $whole) * 100 + (int) str_pad($fraction, 2, '0');
    }

    public static function decimal(int $cents): string
    {
        return sprintf('%d.%02d', intdiv($cents, 100), $cents % 100);
    }
}
