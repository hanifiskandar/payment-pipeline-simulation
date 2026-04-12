<?php

namespace App\Domain;

enum PaymentState: string
{
    case Initiated = 'INITIATED';
    case Authorized = 'AUTHORIZED';
    case PreSettlementReview = 'PRE_SETTLEMENT_REVIEW';
    case Captured = 'CAPTURED';
    case Settled = 'SETTLED';
    case Voided = 'VOIDED';
    case Refunded = 'REFUNDED';
    case Failed = 'FAILED';
}
