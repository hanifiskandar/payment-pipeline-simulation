<?php

namespace App\Handlers;

use App\Services\PaymentStateService;
use App\Services\PaymentStorageService;

abstract class BaseHandler
{
    public function __construct(
        protected PaymentStorageService $storage,
        protected PaymentStateService $stateService,
    ) {}

    /**
     * @param  array<int, string>  $tokens
     */
    abstract public function handle(array $tokens): string;

    protected function ok(string $paymentId, string $state): string
    {
        return "[OK] {$paymentId} {$state}";
    }

    protected function error(string $message): string
    {
        return "[ERROR] {$message}";
    }
}
