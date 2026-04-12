<?php

namespace App\Handlers;

class ListHandler extends BaseHandler
{
    /**
     * @param  array<int, string>  $tokens
     */
    public function handle(array $tokens): string
    {
        $payments = $this->storage->allPayments();

        if (empty($payments)) {
            return '[LIST] No payments found';
        }

        $colId = 14;
        $colState = 22;
        $colAmount = 12;
        $colCurrency = 10;
        $colMerchant = 14;

        $header = str_pad('ID', $colId)
            .str_pad('STATE', $colState)
            .str_pad('AMOUNT', $colAmount)
            .str_pad('CURRENCY', $colCurrency)
            .str_pad('MERCHANT', $colMerchant);

        $divider = str_repeat('-', $colId + $colState + $colAmount + $colCurrency + $colMerchant);

        $rows = [$header, $divider];

        foreach ($payments as $payment) {
            $rows[] = str_pad($payment->paymentId, $colId)
                .str_pad($payment->state->value, $colState)
                .str_pad($payment->amount, $colAmount)
                .str_pad($payment->currency, $colCurrency)
                .str_pad($payment->merchantId, $colMerchant);
        }

        return implode(PHP_EOL, $rows);
    }
}
