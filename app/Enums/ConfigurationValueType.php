<?php

namespace App\Enums;

enum ConfigurationValueType: string
{
    case INTEGER = 'INTEGER';
    case DECIMAL = 'DECIMAL';
    case PERCENTAGE = 'PERCENTAGE';
    case TIME = 'TIME';
    case TIMEZONE = 'TIMEZONE';
    case DURATION = 'DURATION';
    case DATE = 'DATE';
    case TIME_RANGE = 'TIME_RANGE';
    case STRING = 'STRING';
    case JSON = 'JSON';
}
