<?php

namespace App\Services;

use App\Domain\Payment;
use App\Domain\PaymentState;
use App\Exceptions\InvalidTransitionException;
use Illuminate\Support\Carbon;

class PaymentStateService
{
    /**
     * Valid state transitions map.
     *
     * @var array<string, array<string>>
     */
    private const TRANSITIONS = [
        'INITIATED' => ['AUTHORIZED', 'VOIDED'],
        'AUTHORIZED' => ['PRE_SETTLEMENT_REVIEW', 'CAPTURED', 'VOIDED'],
        'PRE_SETTLEMENT_REVIEW' => ['CAPTURED'],
        'CAPTURED' => ['SETTLED', 'REFUNDED'],
        'SETTLED' => ['SETTLED', 'REFUNDED'],
        'VOIDED' => [],
        'REFUNDED' => [],
        'FAILED' => [],
    ];

    public function canTransition(PaymentState $from, PaymentState $to): bool
    {
        $allowed = self::TRANSITIONS[$from->value] ?? [];

        return in_array($to->value, $allowed, true);
    }

    public function transition(Payment $payment, PaymentState $to, string $reason = ''): void
    {
        if (! $this->canTransition($payment->state, $to)) {
            throw new InvalidTransitionException(
                "Payment {$payment->paymentId} cannot transition from {$payment->state->value} to {$to->value}"
            );
        }

        $payment->state = $to;
        $payment->updatedAt = Carbon::now();

        if ($to === PaymentState::Voided && $reason !== '') {
            $payment->voidReason = $reason;
        }

        $entry = "{$to->value} at {$payment->updatedAt->toIso8601String()}";
        if ($reason !== '') {
            $entry .= " [reason: {$reason}]";
        }

        $payment->auditLog[] = $entry;
    }
}
