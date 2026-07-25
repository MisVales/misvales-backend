<?php

declare(strict_types=1);

namespace App\Modules\Client\Domain\Portfolio;

/** Estado de seguimiento sin efecto sobre los libros financieros de MisVales. */
enum PortfolioStatus: string
{
    case PENDING = 'PENDING';
    case PARTIAL = 'PARTIAL';
    case PAID = 'PAID';
}
