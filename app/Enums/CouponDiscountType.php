<?php

namespace App\Enums;

enum CouponDiscountType: string
{
    case Percent = 'percent';
    case Fixed = 'fixed';
}
