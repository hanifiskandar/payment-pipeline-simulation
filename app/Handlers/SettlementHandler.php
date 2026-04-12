<?php

namespace App\Handlers;

use App\Domain\BatchRecord;
use App\Domain\PaymentState;
use Illuminate\Support\Carbon;

class SettlementHandler extends BaseHandler
{
    /**
     * @param  array<int, string>  $tokens
     */
    public function handle(array $tokens): string
    {
        if (count($tokens) < 2) {
            return $this->error('SETTLEMENT requires: batch_id');
        }

        $batchId = $tokens[1];

        if ($this->storage->findBatch($batchId) !== null) {
            return $this->error("Batch {$batchId} already recorded");
        }

        $this->storage->saveBatch(new BatchRecord($batchId, Carbon::now()));

        $settledCount = count(array_filter(
            $this->storage->allPayments(),
            fn ($p) => $p->state === PaymentState::Settled
        ));

        return "[SETTLEMENT] Batch {$batchId} recorded. Settled payments: {$settledCount}";
    }
}
