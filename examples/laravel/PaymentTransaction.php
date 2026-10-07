<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Uzpay\TransactionState;

class PaymentTransaction extends Model
{
    public const CREATED = TransactionState::CREATED;

    public const PERFORMED = TransactionState::PERFORMED;

    protected $fillable = ['payable_type', 'payable_id', 'provider', 'external_id', 'amount', 'state', 'reason', 'create_time', 'perform_time', 'cancel_time', 'payload'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'payload' => 'array',
            'state' => 'integer',
            'reason' => 'integer',
            'create_time' => 'integer',
            'perform_time' => 'integer',
            'cancel_time' => 'integer',
        ];
    }

    public function payable(): MorphTo
    {
        return $this->morphTo();
    }
}
