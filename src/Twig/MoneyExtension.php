<?php

namespace App\Twig;

use Twig\Attribute\AsTwigFilter;

final class MoneyExtension
{
    /**
     * Formats with string arithmetic only, so a money value never passes through a float.
     */
    #[AsTwigFilter('peso')]
    public function peso(string|int|null $value): string
    {
        $amount = bcadd((string) ($value ?? '0'), '0', 2);
        $negative = str_starts_with($amount, '-');
        [$whole, $cents] = explode('.', ltrim($amount, '-'));
        $whole = preg_replace('/\B(?=(\d{3})+$)/', ',', $whole);

        return ($negative ? '-' : '').'₱'.$whole.'.'.$cents;
    }
}
