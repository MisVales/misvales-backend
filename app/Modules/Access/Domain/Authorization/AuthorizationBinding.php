<?php

namespace App\Modules\Access\Domain\Authorization;

final readonly class AuthorizationBinding
{
    /**
     * @param  array<string, mixed>  $parameters
     */
    public function __construct(
        public CriticalAction $action,
        public ?string $resourceType,
        public ?string $resourceId,
        public ?string $branchId,
        public array $parameters,
        public ?string $reason = null,
    ) {}

    public function parametersHash(): string
    {
        return hash('sha256', json_encode(
            self::canonicalize($this->parameters),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(self::canonicalize(...), $value);
        }

        ksort($value, SORT_STRING);

        foreach ($value as $key => $item) {
            $value[$key] = self::canonicalize($item);
        }

        return $value;
    }
}
