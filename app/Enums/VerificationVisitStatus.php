<?php
namespace App\Enums;

enum VerificationVisitStatus: string {
    case ASSIGNED = 'ASSIGNED';
    case IN_PROGRESS = 'IN_PROGRESS';
    case COMPLETED = 'COMPLETED';
}
