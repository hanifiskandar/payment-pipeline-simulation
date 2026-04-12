<?php

namespace App\Domain;

use Illuminate\Support\Carbon;

class Payment
{
    /**
     * @param  array<int, string>  $auditLog
     */
    public function __construct(
        public string $paymentId,
        public string $amount,
        public string $currency,
        public string $merchantId,
        public PaymentState $state,
        public string $refundedAmount = '0.00',
        public ?string $voidReason = null,
        public array $auditLog = [],
        public ?Carbon $createdAt = null,
        public ?Carbon $updatedAt = null,
    ) {
        $this->createdAt ??= Carbon::now();
        $this->updatedAt ??= Carbon::now();
    }
}
