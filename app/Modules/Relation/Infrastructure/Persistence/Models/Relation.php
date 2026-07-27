<?php

declare(strict_types=1);

namespace App\Modules\Relation\Infrastructure\Persistence\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use App\Modules\Relation\Domain\Enums\FinancialStatus;
use App\Modules\Relation\Domain\Enums\PaymentBehavior;

class Relation extends Model
{
    use HasUuids;

    protected $table = 'relations';

    protected $guarded = [];

    protected $casts = [
        'cut_date' => 'date',
        'cut_at' => 'datetime',
        'early_payment_starts_at' => 'datetime',
        'early_payment_ends_at' => 'datetime',
        'due_at' => 'datetime',
        'financial_status' => FinancialStatus::class,
        'under_review' => 'boolean',
        'payment_behavior' => PaymentBehavior::class,
        'portfolio_total' => 'decimal:4',
        'initial_misvales_due_total' => 'decimal:4',
        'surcharge_total' => 'decimal:4',
        'applied_payments_total' => 'decimal:4',
        'outstanding_balance' => 'decimal:4',
        'products_capital_basis' => 'decimal:4',
        'published_at' => 'datetime',
        'lock_version' => 'integer',
    ];

    public function cutRun()
    {
        return $this->belongsTo(CutRun::class, 'cut_run_id');
    }

    public function snapshot()
    {
        return $this->hasOne(RelationSnapshot::class, 'relation_id');
    }

    public function items()
    {
        return $this->hasMany(RelationItem::class, 'relation_id');
    }

    public function documents()
    {
        return $this->hasMany(RelationDocument::class, 'relation_id');
    }
}
