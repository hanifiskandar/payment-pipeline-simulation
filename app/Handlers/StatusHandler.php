<?php

namespace App\Handlers;

class StatusHandler extends BaseHandler
{
    /**
     * @param  array<int, string>  $tokens
     */
    public function handle(array $tokens): string
    {
        if (count($tokens) < 2) {
            return $this->error('STATUS requires: payment_id');
        }

        $paymentId = $tokens[1];
        $payment = $this->storage->findPayment($paymentId);

        if ($payment === null) {
            return $this->error("Payment {$paymentId} not found");
        }

        $refundInfo = bccomp($payment->refundedAmount, '0', 2) > 0
            ? " | refunded: {$payment->refundedAmount}"
            : '';

        return "[STATUS] {$payment->paymentId} | {$payment->state->value} | {$payment->amount} {$payment->currency} | merchant: {$payment->merchantId}{$refundInfo}";    }
}
