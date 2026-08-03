<?php

namespace App\Enums;

enum MarketingConsentSource: string
{
    case Checkout = 'checkout';
    case AccountSettings = 'account_settings';
}
