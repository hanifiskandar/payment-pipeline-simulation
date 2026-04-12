<?php

namespace App\Domain;

use Illuminate\Support\Carbon;

class BatchRecord
{
    public function __construct(
        public string $batchId,
        public Carbon $recordedAt,
    ) {}
}
