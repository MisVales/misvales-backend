<?php
namespace App\Enums;

enum ApplicationAuthorizationDecision: string {
    case APPROVED = 'APPROVED';
    case REJECTED = 'REJECTED';
}
