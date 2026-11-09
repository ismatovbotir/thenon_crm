<?php

namespace App\Support;

class Money
{
    /**
     * Format an amount with the currency of the current locale, e.g. "12 500 сум".
     */
    public static function format(int|float|string|null $amount): string
    {
        return number_format((float) $amount, 0, '.', ' ').' '.__('order.currency');
    }
}
