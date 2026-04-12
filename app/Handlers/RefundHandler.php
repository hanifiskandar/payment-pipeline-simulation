<?php

namespace App\Handlers;

use App\Domain\PaymentState;
use App\Exceptions\InvalidTransitionException;
use Illuminate\Support\Carbon;

class RefundHandler extends BaseHandler
{
    /**
     * @param  array<int, string>  $tokens
     */
    public function handle(array $tokens): string
    {
        if (count($tokens) < 3) {
            return $this->error('REFUND requires: payment_id amount');
        }

        $paymentId = $tokens[1];
        $refundAmount = $tokens[2];

        if (! is_numeric($refundAmount) || bccomp($refundAmount, '0', 2) <= 0) {
            return $this->error("Invalid refund amount: {$refundAmount}");
        }

        $refundAmount = bcadd($refundAmount, '0', 2);

        $payment = $this->storage->findPayment($paymentId);

        if ($payment === null) {
            return $this->error("Payment {$paymentId} not found");
        }

        $validStates = [PaymentState::Captured, PaymentState::Settled];
        if (! in_array($payment->state, $validStates, true)) {
            return $this->error(
                "Payment {$paymentId} cannot be refunded from {$payment->state->value} state"
            );
        }

        $newRefunded = bcadd($payment->refundedAmount, $refundAmount, 2);

        if (bccomp($newRefunded, $payment->amount, 2) > 0) {
            return $this->error(
                "Refund of {$refundAmount} would exceed original amount of {$payment->amount} for {$paymentId}"
            );
        }

        $payment->refundedAmount = $newRefunded;
        $payment->updatedAt = Carbon::now();

        if (bccomp($newRefunded, $payment->amount, 2) === 0) {
            // Fully refunded — transition state
            try {
                $this->stateService->transition($payment, PaymentState::Refunded, 'fully refunded');
            } catch (InvalidTransitionException $e) {
                return $this->error($e->getMessage());
            }
        } else {
            // Partial refund — append audit log without state change
            $payment->auditLog[] = "PARTIAL_REFUND {$refundAmount} at {$payment->updatedAt->toIso8601String()} [refunded so far: {$newRefunded}/{$payment->amount}]";
        }

        $this->storage->savePayment($payment);

        $remaining = bcsub($payment->amount, $newRefunded, 2);
        $message = "refunded {$refundAmount} | total refunded: {$newRefunded} | remaining: {$remaining} | state: {$payment->state->value}";
        return $this->ok($paymentId, $message);
    }
}
