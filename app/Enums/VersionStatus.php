<?php

namespace App\Enums;

enum VersionStatus: string
{
    case DRAFT = 'DRAFT';
    case PUBLISHED = 'PUBLISHED';
    case INACTIVE = 'INACTIVE';
}
