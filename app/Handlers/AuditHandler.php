<?php

namespace App\Handlers;

class AuditHandler extends BaseHandler
{
    /**
     * @param  array<int, string>  $tokens
     */
    public function handle(array $tokens): string
    {
        $paymentId = $tokens[1] ?? 'UNKNOWN';

        return "[AUDIT] RECEIVED for {$paymentId}";
    }
}
