<?php

namespace App\Handlers;

use App\Domain\PaymentState;
use App\Exceptions\InvalidTransitionException;

class CaptureHandler extends BaseHandler
{
    /**
     * @param  array<int, string>  $tokens
     */
    public function handle(array $tokens): string
    {
        if (count($tokens) < 2) {
            return $this->error('CAPTURE requires: payment_id');
        }

        $paymentId = $tokens[1];
        $payment = $this->storage->findPayment($paymentId);

        if ($payment === null) {
            return $this->error("Payment {$paymentId} not found");
        }

        try {
            $this->stateService->transition($payment, PaymentState::Captured);
        } catch (InvalidTransitionException $e) {
            return $this->error($e->getMessage());
        }

        $this->storage->savePayment($payment);

        return $this->ok($paymentId, PaymentState::Captured->value);
    }
}
