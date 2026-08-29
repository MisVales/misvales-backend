<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class RelacionPartidaDistribuidora extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $table = 'distributor_relation_items';

    protected $guarded = [];

    protected static function booted(): void
    {
        self::creating(function (self $item): void {
            $item->occurrence_type ??= 'INSTALLMENT';
            if ($item->occurrence_type === 'INSTALLMENT') {
                $item->source_voucher_installment_id ??= $item->voucher_installment_id;
            }
        });
    }

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'portfolio_amount' => 'decimal:4', 'misvales_amount' => 'decimal:4'];
    }

    public function installment(): BelongsTo
    {
        return $this->belongsTo(ParcialidadVale::class, 'voucher_installment_id');
    }

    public function sourceInstallment(): BelongsTo
    {
        return $this->belongsTo(ParcialidadVale::class, 'source_voucher_installment_id');
    }

    public function previousTerminalOccurrence(): BelongsTo
    {
        return $this->belongsTo(self::class, 'previous_terminal_occurrence_id');
    }
}
