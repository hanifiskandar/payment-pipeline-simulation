<?php

namespace App\Handlers;

use App\Domain\Payment;
use App\Domain\PaymentState;
use Illuminate\Support\Carbon;

class CreateHandler extends BaseHandler
{
    /**
     * @param  array<int, string>  $tokens
     */
    public function handle(array $tokens): string
    {
        if (count($tokens) < 5) {
            return $this->error('CREATE requires: payment_id amount currency merchant_id');
        }

        [, $paymentId, $amount, $currency, $merchantId] = $tokens;

        if (! is_numeric($amount) || bccomp($amount, '0', 2) <= 0) {
            return $this->error("Invalid amount: {$amount}");
        }

        $currency = strtoupper($currency);

        if (strlen($currency) !== 3) {
            return $this->error("Currency must be 3 letters: {$currency}");
        }

        $supported = array_map('strtoupper', config('payment.supported_currencies'));
        if (! in_array($currency, $supported, true)) {
            return $this->error("Unsupported currency: {$currency}");
        }

        // Normalise amount to 2 decimal places for consistent comparison
        $amount = bcadd($amount, '0', 2);

        $existing = $this->storage->findPayment($paymentId);

        if ($existing === null) {
            $payment = new Payment(
                paymentId: $paymentId,
                amount: $amount,
                currency: $currency,
                merchantId: $merchantId,
                state: PaymentState::Initiated,
            );
            $payment->auditLog[] = 'INITIATED at '.Carbon::now()->toIso8601String();
            $this->storage->savePayment($payment);

            return $this->ok($paymentId, PaymentState::Initiated->value);
        }

        // Idempotency: same details = no-op
        if (
            bccomp($existing->amount, $amount, 2) === 0
            && $existing->currency === $currency
            && $existing->merchantId === $merchantId
        ) {
            return $this->ok($paymentId, PaymentState::Initiated->value);
        }

        // Conflict: different details — mark existing as FAILED
        $existing->state = PaymentState::Failed;
        $existing->updatedAt = Carbon::now();
        $existing->auditLog[] = 'FAILED at '.$existing->updatedAt->toIso8601String().' [reason: duplicate payment_id with different details]';
        $this->storage->savePayment($existing);

        return $this->error("{$paymentId} already exists with different details");
    }
}
