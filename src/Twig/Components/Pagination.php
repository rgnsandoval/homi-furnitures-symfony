<?php

namespace App\Twig\Components;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent]
final class Pagination
{
    public int $total = 0;
    public int $page = 1;
    public int $perPage = 20;

    public function getPages(): int
    {
        return max(1, intdiv($this->total + $this->perPage - 1, $this->perPage));
    }
}
