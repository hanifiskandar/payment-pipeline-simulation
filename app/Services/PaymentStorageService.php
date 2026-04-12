<?php

namespace App\Services;

use App\Domain\BatchRecord;
use App\Domain\Payment;

class PaymentStorageService
{
    /** @var array<string, Payment> */
    private array $payments = [];

    /** @var array<string, BatchRecord> */
    private array $batches = [];

    public function findPayment(string $paymentId): ?Payment
    {
        return $this->payments[$paymentId] ?? null;
    }

    public function savePayment(Payment $payment): void
    {
        $this->payments[$payment->paymentId] = $payment;
    }

    /** @return array<string, Payment> */
    public function allPayments(): array
    {
        return $this->payments;
    }

    public function findBatch(string $batchId): ?BatchRecord
    {
        return $this->batches[$batchId] ?? null;
    }

    public function saveBatch(BatchRecord $batch): void
    {
        $this->batches[$batch->batchId] = $batch;
    }

    /** @return array<string, BatchRecord> */
    public function allBatches(): array
    {
        return $this->batches;
    }
}
