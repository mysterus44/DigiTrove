<?php

namespace App\Enums;

enum MarketingConsentAction: string
{
    case Granted = 'granted';
    case Withdrawn = 'withdrawn';
}
