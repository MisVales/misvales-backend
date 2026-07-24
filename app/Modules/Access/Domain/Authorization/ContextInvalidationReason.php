<?php

namespace App\Modules\Access\Domain\Authorization;

enum ContextInvalidationReason: string
{
    case PERMISSION_CHANGED = 'permission_changed';
    case BRANCH_CHANGED = 'branch_changed';
    case HIERARCHY_CHANGED = 'hierarchy_changed';
    case ASSIGNMENT_CHANGED = 'assignment_changed';
}
