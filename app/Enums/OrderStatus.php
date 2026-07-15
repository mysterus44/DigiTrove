<?php

namespace App\Enums;

enum OrderStatus: string
{
    case Pending = 'pending';
    case PaymentReview = 'payment_review';
    case Paid = 'paid';
    case PartiallyRefunded = 'partially_refunded';
    case Refunded = 'refunded';
    case Cancelled = 'cancelled';
    case Expired = 'expired';
}
