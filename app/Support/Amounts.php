<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

final class Amounts
{
    public static function scaled(mixed $value, int $decimals = 2): int
    {
        $v = (string) $value;
        if (! preg_match('/^\d{1,9}(?:\.\d{1,'.$decimals.'})?$/D', $v)) {
            throw ValidationException::withMessages(['amount' => 'قيمة رقمية غير صحيحة أو تتجاوز عدد المنازل المسموح.']);
        }
        [$a,$b] = array_pad(explode('.', $v, 2), 2, '');

        return (int) $a * (10 ** $decimals) + (int) str_pad($b, $decimals, '0');
    }

    public static function money(int $minor): string
    {
        return ($minor < 0 ? '-' : '').intdiv(abs($minor), 100).'.'.str_pad((string) (abs($minor) % 100), 2, '0', STR_PAD_LEFT);
    }

    public static function quantity(int $milli): string
    {
        return rtrim(rtrim((($milli < 0 ? '-' : '').intdiv(abs($milli), 1000).'.'.str_pad((string) (abs($milli) % 1000), 3, '0', STR_PAD_LEFT)), '0'), '.') ?: '0';
    }

    public static function line(int $quantity, int $price): int
    {
        return (int) bcdiv(bcadd(bcmul((string) $quantity, (string) $price, 0), '500', 0), '1000', 0);
    }

    public static function tax(int $subtotal, int $basis): int
    {
        return (int) bcdiv(bcadd(bcmul((string) $subtotal,(string) $basis,0),'5000',0), '10000', 0);
    }
}
