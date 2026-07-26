<?php

declare(strict_types=1);

namespace App\Modules\Voucher\Infrastructure\Persistence\Eloquent\Models;

use App\Modules\Voucher\Domain\Enums\DataChangeOperation;
use App\Modules\Voucher\Infrastructure\Persistence\Eloquent\Models\Concerns\UsesUuidPrimaryKey;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * @property string $id
 * @property string $data_change_request_id
 * @property string $token_hash
 * @property int $cashier_id
 * @property string $voucher_id
 * @property string $client_id
 * @property int $branch_id
 * @property DataChangeOperation $operation
 * @property list<string> $field_scope
 * @property int $issued_by
 * @property CarbonImmutable $issued_at
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $consumed_at
 * @property CarbonImmutable|null $revoked_at
 */
#[Hidden(['token_hash'])]
final class AuthorizationTokenModel extends Model
{
    use UsesUuidPrimaryKey;

    protected $table = 'authorization_tokens';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'operation' => DataChangeOperation::class,
            'field_scope' => 'array',
            'issued_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'consumed_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        self::deleting(fn (): never => throw new LogicException('Los tokens de autorización no se eliminan.'));
    }
}
