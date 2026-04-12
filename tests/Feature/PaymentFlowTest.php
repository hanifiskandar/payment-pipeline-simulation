<?php

namespace Tests\Feature;

use App\Domain\PaymentState;
use App\Services\PaymentPipelineService;
use App\Services\PaymentStorageService;
use Tests\TestCase;

class PaymentFlowTest extends TestCase
{
    private PaymentPipelineService $pipeline;

    protected function setUp(): void
    {
        parent::setUp();

        // Fresh in-memory store per test
        $this->app->singleton(PaymentStorageService::class, fn () => new PaymentStorageService);
        $this->pipeline = $this->app->make(PaymentPipelineService::class);
    }

    // --- Happy paths ---

    public function test_full_happy_path_create_authorize_capture_settle(): void
    {
        $this->assertSame('[OK] P1001 INITIATED', $this->pipeline->handle('CREATE P1001 10.00 MYR M01'));
        $this->assertSame('[OK] P1001 AUTHORIZED', $this->pipeline->handle('AUTHORIZE P1001'));
        $this->assertSame('[OK] P1001 CAPTURED', $this->pipeline->handle('CAPTURE P1001'));
        $this->assertSame('[OK] P1001 SETTLED', $this->pipeline->handle('SETTLE P1001'));
    }

    public function test_status_returns_correct_format(): void
    {
        $this->pipeline->handle('CREATE P1001 10.00 MYR M01');

        $this->assertSame(
            '[STATUS] P1001 | INITIATED | 10.00 MYR | merchant: M01',
            $this->pipeline->handle('STATUS P1001')
        );
    }

    public function test_void_from_initiated(): void
    {
        $this->pipeline->handle('CREATE P1001 10.00 MYR M01');

        $this->assertSame('[OK] P1001 VOIDED', $this->pipeline->handle('VOID P1001'));
    }

    public function test_void_from_authorized_with_reason(): void
    {
        $this->pipeline->handle('CREATE P1001 10.00 MYR M01');
        $this->pipeline->handle('AUTHORIZE P1001');

        $result = $this->pipeline->handle('VOID P1001 fraud detected');

        $this->assertSame('[OK] P1001 VOIDED', $result);

        $storage = $this->app->make(PaymentStorageService::class);
        $this->assertSame('fraud detected', $storage->findPayment('P1001')->voidReason);
    }

    public function test_high_amount_auto_triggers_pre_settlement_review(): void
    {
        $this->pipeline->handle('CREATE P1001 600.00 MYR M01');

        $this->assertSame('[OK] P1001 PRE_SETTLEMENT_REVIEW', $this->pipeline->handle('AUTHORIZE P1001'));
    }

    public function test_amount_at_threshold_does_not_trigger_review(): void
    {
        $this->pipeline->handle('CREATE P1001 500.00 MYR M01');

        $this->assertSame('[OK] P1001 AUTHORIZED', $this->pipeline->handle('AUTHORIZE P1001'));
    }

    public function test_pre_settlement_review_path_can_be_captured_and_settled(): void
    {
        $this->pipeline->handle('CREATE P1001 600.00 MYR M01');
        $this->pipeline->handle('AUTHORIZE P1001');
        $this->assertSame('[OK] P1001 CAPTURED', $this->pipeline->handle('CAPTURE P1001'));
        $this->assertSame('[OK] P1001 SETTLED', $this->pipeline->handle('SETTLE P1001'));
    }

    // --- Refund paths ---

    public function test_full_refund_transitions_to_refunded(): void
    {
        $this->pipeline->handle('CREATE P1001 100.00 MYR M01');
        $this->pipeline->handle('AUTHORIZE P1001');
        $this->pipeline->handle('CAPTURE P1001');

        $this->assertSame('[OK] P1001 refunded 100.00 | total refunded: 100.00 | remaining: 0.00 | state: REFUNDED', $this->pipeline->handle('REFUND P1001 100.00'));
    }

    public function test_partial_refund_keeps_state_unchanged(): void
    {
        $this->pipeline->handle('CREATE P1001 100.00 MYR M01');
        $this->pipeline->handle('AUTHORIZE P1001');
        $this->pipeline->handle('CAPTURE P1001');

        $this->assertSame('[OK] P1001 refunded 40.00 | total refunded: 40.00 | remaining: 60.00 | state: CAPTURED', $this->pipeline->handle('REFUND P1001 40.00'));

        $storage = $this->app->make(PaymentStorageService::class);
        $payment = $storage->findPayment('P1001');
        $this->assertSame('40.00', $payment->refundedAmount);
        $this->assertSame(PaymentState::Captured, $payment->state);
    }

    public function test_multiple_partial_refunds_accumulate(): void
    {
        $this->pipeline->handle('CREATE P1001 100.00 MYR M01');
        $this->pipeline->handle('AUTHORIZE P1001');
        $this->pipeline->handle('CAPTURE P1001');

        $this->pipeline->handle('REFUND P1001 30.00');
        $this->pipeline->handle('REFUND P1001 30.00');
        $result = $this->pipeline->handle('REFUND P1001 40.00');

        $this->assertSame('[OK] P1001 refunded 40.00 | total refunded: 100.00 | remaining: 0.00 | state: REFUNDED', $result);
    }

    public function test_refund_over_amount_is_rejected(): void
    {
        $this->pipeline->handle('CREATE P1001 100.00 MYR M01');
        $this->pipeline->handle('AUTHORIZE P1001');
        $this->pipeline->handle('CAPTURE P1001');

        $result = $this->pipeline->handle('REFUND P1001 150.00');

        $this->assertStringContainsString('[ERROR]', $result);
        $this->assertStringContainsString('exceed', $result);
    }

    public function test_refund_from_settled_state(): void
    {
        $this->pipeline->handle('CREATE P1001 100.00 MYR M01');
        $this->pipeline->handle('AUTHORIZE P1001');
        $this->pipeline->handle('CAPTURE P1001');
        $this->pipeline->handle('SETTLE P1001');

        $this->assertSame('[OK] P1001 refunded 100.00 | total refunded: 100.00 | remaining: 0.00 | state: REFUNDED', $this->pipeline->handle('REFUND P1001 100.00'));
    }

    // --- Invalid transitions ---

    public function test_refund_before_capture_is_rejected(): void
    {
        $this->pipeline->handle('CREATE P1001 100.00 MYR M01');
        $this->pipeline->handle('AUTHORIZE P1001');

        $result = $this->pipeline->handle('REFUND P1001 50.00');

        $this->assertStringContainsString('[ERROR]', $result);
    }

    public function test_capture_before_authorize_is_rejected(): void
    {
        $this->pipeline->handle('CREATE P1001 100.00 MYR M01');

        $result = $this->pipeline->handle('CAPTURE P1001');

        $this->assertStringContainsString('[ERROR]', $result);
    }

    public function test_void_after_capture_is_rejected(): void
    {
        $this->pipeline->handle('CREATE P1001 100.00 MYR M01');
        $this->pipeline->handle('AUTHORIZE P1001');
        $this->pipeline->handle('CAPTURE P1001');

        $result = $this->pipeline->handle('VOID P1001');

        $this->assertStringContainsString('[ERROR]', $result);
    }

    public function test_settle_before_capture_is_rejected(): void
    {
        $this->pipeline->handle('CREATE P1001 100.00 MYR M01');
        $this->pipeline->handle('AUTHORIZE P1001');

        $result = $this->pipeline->handle('SETTLE P1001');

        $this->assertStringContainsString('[ERROR]', $result);
    }

    // --- Idempotency ---

    public function test_duplicate_create_same_details_is_idempotent(): void
    {
        $this->assertSame('[OK] P1001 INITIATED', $this->pipeline->handle('CREATE P1001 10.00 MYR M01'));
        $this->assertSame('[OK] P1001 INITIATED', $this->pipeline->handle('CREATE P1001 10.00 MYR M01'));
    }

    public function test_duplicate_create_different_details_fails_both(): void
    {
        $this->pipeline->handle('CREATE P1001 10.00 MYR M01');
        $result = $this->pipeline->handle('CREATE P1001 20.00 MYR M01');

        $this->assertStringContainsString('[ERROR]', $result);

        $storage = $this->app->make(PaymentStorageService::class);
        $this->assertSame(PaymentState::Failed, $storage->findPayment('P1001')->state);
    }

    public function test_settle_on_already_settled_payment_is_idempotent(): void
    {
        $this->pipeline->handle('CREATE P1001 10.00 MYR M01');
        $this->pipeline->handle('AUTHORIZE P1001');
        $this->pipeline->handle('CAPTURE P1001');
        $this->pipeline->handle('SETTLE P1001');

        $this->assertSame('[OK] P1001 SETTLED', $this->pipeline->handle('SETTLE P1001'));
    }

    // --- SETTLEMENT ---

    public function test_settlement_records_batch_and_counts_settled_payments(): void
    {
        $this->pipeline->handle('CREATE P1001 10.00 MYR M01');
        $this->pipeline->handle('AUTHORIZE P1001');
        $this->pipeline->handle('CAPTURE P1001');
        $this->pipeline->handle('SETTLE P1001');

        $this->pipeline->handle('CREATE P1002 20.00 MYR M02');
        $this->pipeline->handle('AUTHORIZE P1002');
        $this->pipeline->handle('CAPTURE P1002');
        $this->pipeline->handle('SETTLE P1002');

        $result = $this->pipeline->handle('SETTLEMENT BATCH001');

        $this->assertSame('[SETTLEMENT] Batch BATCH001 recorded. Settled payments: 2', $result);
    }

    public function test_duplicate_settlement_batch_id_is_rejected(): void
    {
        $this->pipeline->handle('SETTLEMENT BATCH001');
        $result = $this->pipeline->handle('SETTLEMENT BATCH001');

        $this->assertStringContainsString('[ERROR]', $result);
        $this->assertStringContainsString('already recorded', $result);
    }

    // --- AUDIT ---

    public function test_audit_has_zero_side_effects(): void
    {
        $this->pipeline->handle('CREATE P1001 10.00 MYR M01');
        $result = $this->pipeline->handle('AUDIT P1001');

        $this->assertSame('[AUDIT] RECEIVED for P1001', $result);

        $storage = $this->app->make(PaymentStorageService::class);
        $this->assertSame(PaymentState::Initiated, $storage->findPayment('P1001')->state);
    }

    // --- LIST ---

    public function test_list_shows_all_payments(): void
    {
        $this->pipeline->handle('CREATE P1001 10.00 MYR M01');
        $this->pipeline->handle('CREATE P1002 20.00 USD M02');

        $result = $this->pipeline->handle('LIST');

        $this->assertStringContainsString('P1001', $result);
        $this->assertStringContainsString('P1002', $result);
    }

    public function test_list_when_empty(): void
    {
        $this->assertSame('[LIST] No payments found', $this->pipeline->handle('LIST'));
    }

    // --- Parser integration ---

    public function test_blank_line_returns_null(): void
    {
        $this->assertNull($this->pipeline->handle(''));
    }

    public function test_unknown_command_returns_error(): void
    {
        $result = $this->pipeline->handle('FOOBAR P1001');

        $this->assertSame('[ERROR] Unknown command: FOOBAR', $result);
    }

    public function test_hash_at_position_1_treated_as_unknown_command(): void
    {
        $result = $this->pipeline->handle('# CREATE P1001 10.00 MYR M01');

        $this->assertStringContainsString('[ERROR]', $result);
        $this->assertStringContainsString('Unknown command', $result);
    }

    public function test_inline_comment_stripped_correctly(): void
    {
        $result = $this->pipeline->handle('CREATE P1001 10.00 MYR M01 # this is a test payment');

        $this->assertSame('[OK] P1001 INITIATED', $result);
    }

    public function test_void_with_reason_containing_embedded_hash(): void
    {
        $this->pipeline->handle('CREATE P1001 10.00 MYR M01');
        $result = $this->pipeline->handle('VOID P1001 REASON#CODE');

        $this->assertSame('[OK] P1001 VOIDED', $result);

        $storage = $this->app->make(PaymentStorageService::class);
        $this->assertSame('REASON#CODE', $storage->findPayment('P1001')->voidReason);
    }

    // --- Not found ---

    public function test_status_for_unknown_payment(): void
    {
        $result = $this->pipeline->handle('STATUS UNKNOWN');

        $this->assertSame('[ERROR] Payment UNKNOWN not found', $result);
    }
}
