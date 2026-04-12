<?php

namespace App\Handlers;

use App\Domain\PaymentState;
use App\Exceptions\InvalidTransitionException;
use Illuminate\Support\Carbon;

class SettleHandler extends BaseHandler
{
    /**
     * @param  array<int, string>  $tokens
     */
    public function handle(array $tokens): string
    {
        if (count($tokens) < 2) {
            return $this->error('SETTLE requires: payment_id');
        }

        $paymentId = $tokens[1];
        $payment = $this->storage->findPayment($paymentId);

        if ($payment === null) {
            return $this->error("Payment {$paymentId} not found");
        }

        // Idempotent: already settled is fine
        if ($payment->state === PaymentState::Settled) {
            $payment->updatedAt = Carbon::now();
            $this->storage->savePayment($payment);

            return $this->ok($paymentId, PaymentState::Settled->value);
        }

        try {
            $this->stateService->transition($payment, PaymentState::Settled);
        } catch (InvalidTransitionException $e) {
            return $this->error($e->getMessage());
        }

        $this->storage->savePayment($payment);

        return $this->ok($paymentId, PaymentState::Settled->value);
    }
}
