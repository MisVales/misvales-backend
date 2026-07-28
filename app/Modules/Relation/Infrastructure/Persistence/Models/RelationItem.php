<?php

declare(strict_types=1);

namespace App\Modules\Relation\Infrastructure\Persistence\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class RelationItem extends Model
{
    use HasUuids;

    protected $table = 'relation_items';

    protected $guarded = [];

    protected $casts = [
        'payment_number' => 'integer',
        'total_payments' => 'integer',
        'product_snapshot' => 'array',
        'category_snapshot' => 'array',
        'capital_amount' => 'decimal:4',
        'loan_commission_amount' => 'decimal:4',
        'interest_amount' => 'decimal:4',
        'insurance_amount' => 'decimal:4',
        'distributor_profit_amount' => 'decimal:4',
        'base_payment_amount' => 'decimal:4',
        'client_charge_amount' => 'decimal:4',
        'misvales_due_amount' => 'decimal:4',
        'surcharge_amount' => 'decimal:4',
        'applied_amount' => 'decimal:4',
        'outstanding_amount' => 'decimal:4',
        'sort_order' => 'integer',
    ];

    public function relation()
    {
        return $this->belongsTo(Relation::class, 'relation_id');
    }
}
