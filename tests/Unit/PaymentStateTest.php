<?php

namespace Tests\Unit;

use App\Domain\Payment;
use App\Domain\PaymentState;
use App\Exceptions\InvalidTransitionException;
use App\Services\PaymentStateService;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

class PaymentStateTest extends TestCase
{
    private PaymentStateService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new PaymentStateService;
    }

    // --- canTransition: valid paths ---

    public function test_initiated_can_transition_to_authorized(): void
    {
        $this->assertTrue($this->service->canTransition(PaymentState::Initiated, PaymentState::Authorized));
    }

    public function test_initiated_can_transition_to_voided(): void
    {
        $this->assertTrue($this->service->canTransition(PaymentState::Initiated, PaymentState::Voided));
    }

    public function test_authorized_can_transition_to_pre_settlement_review(): void
    {
        $this->assertTrue($this->service->canTransition(PaymentState::Authorized, PaymentState::PreSettlementReview));
    }

    public function test_authorized_can_transition_to_captured(): void
    {
        $this->assertTrue($this->service->canTransition(PaymentState::Authorized, PaymentState::Captured));
    }

    public function test_authorized_can_transition_to_voided(): void
    {
        $this->assertTrue($this->service->canTransition(PaymentState::Authorized, PaymentState::Voided));
    }

    public function test_pre_settlement_review_can_transition_to_captured(): void
    {
        $this->assertTrue($this->service->canTransition(PaymentState::PreSettlementReview, PaymentState::Captured));
    }

    public function test_captured_can_transition_to_settled(): void
    {
        $this->assertTrue($this->service->canTransition(PaymentState::Captured, PaymentState::Settled));
    }

    public function test_captured_can_transition_to_refunded(): void
    {
        $this->assertTrue($this->service->canTransition(PaymentState::Captured, PaymentState::Refunded));
    }

    public function test_settled_can_transition_to_settled_for_idempotency(): void
    {
        $this->assertTrue($this->service->canTransition(PaymentState::Settled, PaymentState::Settled));
    }

    public function test_settled_can_transition_to_refunded(): void
    {
        $this->assertTrue($this->service->canTransition(PaymentState::Settled, PaymentState::Refunded));
    }

    // --- canTransition: invalid paths ---

    public function test_initiated_cannot_transition_to_captured(): void
    {
        $this->assertFalse($this->service->canTransition(PaymentState::Initiated, PaymentState::Captured));
    }

    public function test_voided_is_terminal(): void
    {
        $this->assertFalse($this->service->canTransition(PaymentState::Voided, PaymentState::Initiated));
        $this->assertFalse($this->service->canTransition(PaymentState::Voided, PaymentState::Authorized));
    }

    public function test_refunded_is_terminal(): void
    {
        $this->assertFalse($this->service->canTransition(PaymentState::Refunded, PaymentState::Captured));
    }

    public function test_failed_is_terminal(): void
    {
        $this->assertFalse($this->service->canTransition(PaymentState::Failed, PaymentState::Initiated));
    }

    public function test_captured_cannot_go_back_to_authorized(): void
    {
        $this->assertFalse($this->service->canTransition(PaymentState::Captured, PaymentState::Authorized));
    }

    // --- transition() behaviour ---

    public function test_transition_updates_state(): void
    {
        $payment = $this->makePayment(PaymentState::Initiated);
        $this->service->transition($payment, PaymentState::Authorized);

        $this->assertSame(PaymentState::Authorized, $payment->state);
    }

    public function test_transition_updates_updated_at(): void
    {
        $payment = $this->makePayment(PaymentState::Initiated);
        $before = Carbon::now();
        $this->service->transition($payment, PaymentState::Authorized);

        $this->assertTrue($payment->updatedAt->greaterThanOrEqualTo($before));
    }

    public function test_transition_appends_to_audit_log(): void
    {
        $payment = $this->makePayment(PaymentState::Initiated);
        $this->service->transition($payment, PaymentState::Authorized);

        $this->assertCount(1, $payment->auditLog);
        $this->assertStringContainsString('AUTHORIZED', $payment->auditLog[0]);
    }

    public function test_transition_includes_reason_in_audit_log(): void
    {
        $payment = $this->makePayment(PaymentState::Authorized);
        $this->service->transition($payment, PaymentState::Voided, 'fraud detected');

        $this->assertStringContainsString('fraud detected', $payment->auditLog[0]);
    }

    public function test_transition_sets_void_reason_when_voiding(): void
    {
        $payment = $this->makePayment(PaymentState::Initiated);
        $this->service->transition($payment, PaymentState::Voided, 'customer request');

        $this->assertSame('customer request', $payment->voidReason);
    }

    public function test_transition_does_not_set_void_reason_for_other_transitions(): void
    {
        $payment = $this->makePayment(PaymentState::Initiated);
        $this->service->transition($payment, PaymentState::Authorized, 'some reason');

        $this->assertNull($payment->voidReason);
    }

    public function test_transition_throws_on_invalid_path(): void
    {
        $this->expectException(InvalidTransitionException::class);

        $payment = $this->makePayment(PaymentState::Initiated);
        $this->service->transition($payment, PaymentState::Captured);
    }

    private function makePayment(PaymentState $state): Payment
    {
        return new Payment(
            paymentId: 'P_TEST',
            amount: '100.00',
            currency: 'MYR',
            merchantId: 'M01',
            state: $state,
        );
    }
}
