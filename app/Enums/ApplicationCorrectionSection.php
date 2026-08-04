<?php
namespace App\Enums;

enum ApplicationCorrectionSection: string {
    case PERSONAL_DATA = 'personal_data';
    case FAMILY_MEMBERS = 'family_members';
    case RESIDENCES = 'residences';
    case VEHICLES = 'vehicles';
    case ASSETS_LIABILITIES = 'assets_liabilities';
    case EMPLOYMENTS = 'employments';
    case COMMERCIAL_CREDITS = 'commercial_credits';
}
