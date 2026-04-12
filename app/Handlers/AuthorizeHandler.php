<?php

namespace App\Handlers;

use App\Domain\PaymentState;
use App\Exceptions\InvalidTransitionException;

class AuthorizeHandler extends BaseHandler
{
    /**
     * @param  array<int, string>  $tokens
     */
    public function handle(array $tokens): string
    {
        if (count($tokens) < 2) {
            return $this->error('AUTHORIZE requires: payment_id');
        }

        $paymentId = $tokens[1];
        $payment = $this->storage->findPayment($paymentId);

        if ($payment === null) {
            return $this->error("Payment {$paymentId} not found");
        }

        try {
            $this->stateService->transition($payment, PaymentState::Authorized);
        } catch (InvalidTransitionException $e) {
            return $this->error($e->getMessage());
        }

        // Auto-transition to PRE_SETTLEMENT_REVIEW if amount exceeds threshold
        $threshold = (string) config('payment.review_threshold');
        if (bccomp($payment->amount, $threshold, 2) > 0) {
            try {
                $this->stateService->transition(
                    $payment,
                    PaymentState::PreSettlementReview,
                    'auto: amount exceeds review threshold'
                );
            } catch (InvalidTransitionException $e) {
                return $this->error($e->getMessage());
            }
        }

        $this->storage->savePayment($payment);

        return $this->ok($paymentId, $payment->state->value);
    }
}
