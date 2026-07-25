<?php

declare(strict_types=1);

namespace App\Modules\DistributorOnboarding\Domain\Expedients;

/** Secciones persistentes definidas para el expediente de M04. */
enum ExpedientSection: string
{
    case CONTACT = 'CONTACT';
    case PERSONAL = 'PERSONAL';
    case FAMILY_MEMBER = 'FAMILY_MEMBER';
    case FAMILY_REFERENCE = 'FAMILY_REFERENCE';
    case RESIDENCE = 'RESIDENCE';
    case VEHICLE = 'VEHICLE';
    case ASSET_LIABILITY = 'ASSET_LIABILITY';
    case EMPLOYMENT = 'EMPLOYMENT';
    case LABOR_REFERENCE = 'LABOR_REFERENCE';
    case COMMERCIAL_CREDIT = 'COMMERCIAL_CREDIT';
}
