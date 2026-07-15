<?php

namespace App\Support;

/**
 * Indian-system currency presentation: lakh/crore words and 2,2,3 digit
 * grouping. PHP's intl spellout speaks western ("one hundred twenty-five
 * thousand"), which is wrong on an Indian payslip — hence hand-rolled.
 */
class IndianCurrency
{
    private const ONES = [
        '', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine',
        'Ten', 'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen',
        'Seventeen', 'Eighteen', 'Nineteen',
    ];

    private const TENS = [
        '', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety',
    ];

    /**
     * "₹51,254.00" → "Fifty One Thousand Two Hundred Fifty Four Rupees Only".
     * Paise are voiced only when present.
     */
    public static function words(float $amount): string
    {
        $amount = round(abs($amount), 2);
        $rupees = (int) floor($amount);
        $paise = (int) round(($amount - $rupees) * 100);

        if ($rupees === 0 && $paise === 0) {
            return 'Zero Rupees Only';
        }

        $parts = [];

        if ($rupees > 0) {
            $parts[] = self::integerWords($rupees).' Rupee'.($rupees === 1 ? '' : 's');
        }

        if ($paise > 0) {
            $parts[] = ($rupees > 0 ? 'and ' : '').self::integerWords($paise).' Paise';
        }

        return implode(' ', $parts).' Only';
    }

    /** Indian 2,2,3 grouping: 12500000 → "1,25,00,000.00". */
    public static function format(float $amount, int $decimals = 2): string
    {
        $sign = $amount < 0 ? '-' : '';
        $amount = round(abs($amount), $decimals);

        $whole = (string) (int) floor($amount);
        $fraction = $decimals > 0
            ? '.'.str_pad((string) (int) round(($amount - floor($amount)) * (10 ** $decimals)), $decimals, '0', STR_PAD_LEFT)
            : '';

        if (strlen($whole) > 3) {
            $last3 = substr($whole, -3);
            $rest = substr($whole, 0, -3);
            // Group the remaining digits in pairs, right to left.
            $rest = strrev(implode(',', str_split(strrev($rest), 2)));
            $whole = $rest.','.$last3;
        }

        return $sign.$whole.$fraction;
    }

    /** Spell an integer in the Indian system (crore → lakh → thousand → hundred). */
    private static function integerWords(int $number): string
    {
        if ($number === 0) {
            return 'Zero';
        }

        $words = [];

        foreach ([
            [10000000, 'Crore'],
            [100000, 'Lakh'],
            [1000, 'Thousand'],
            [100, 'Hundred'],
        ] as [$divisor, $label]) {
            if ($number >= $divisor) {
                $words[] = self::integerWords(intdiv($number, $divisor)).' '.$label;
                $number %= $divisor;
            }
        }

        if ($number > 0) {
            if ($number < 20) {
                $words[] = self::ONES[$number];
            } else {
                $tens = self::TENS[intdiv($number, 10)];
                $ones = $number % 10;
                $words[] = $tens.($ones > 0 ? ' '.self::ONES[$ones] : '');
            }
        }

        return implode(' ', $words);
    }
}
