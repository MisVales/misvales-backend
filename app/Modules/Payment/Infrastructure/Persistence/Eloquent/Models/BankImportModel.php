<?php

declare(strict_types=1);

namespace App\Modules\Payment\Infrastructure\Persistence\Eloquent\Models;

use App\Modules\Payment\Domain\Enums\BankImportStatus;
use App\Modules\Payment\Infrastructure\Persistence\Eloquent\Models\Concerns\UsesUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** @property string $id @property int $branch_id @property int $uploaded_by @property BankImportStatus $status */
final class BankImportModel extends Model
{
    use UsesUuidPrimaryKey;

    protected $table = 'bank_imports';

    /** @var list<string> */
    protected $guarded = ['id'];

    /** @return HasMany<BankMovementModel, $this> */
    public function movements(): HasMany
    {
        return $this->hasMany(BankMovementModel::class, 'bank_import_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'business_date' => 'immutable_date',
            'status' => BankImportStatus::class,
            'headers' => 'array',
            'file_metadata' => 'array',
            'error_summary' => 'array',
            'processing_started_at' => 'immutable_datetime',
            'processing_finished_at' => 'immutable_datetime',
            'lock_version' => 'integer',
        ];
    }
}
