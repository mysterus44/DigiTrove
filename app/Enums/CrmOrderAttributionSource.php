<?php

namespace App\Enums;

enum CrmOrderAttributionSource: string
{
    case ExistingContactSnapshot = 'existing_contact_snapshot';
    case VerifiedAccountResolution = 'verified_account_resolution';
    case GuestOrderResolution = 'guest_order_resolution';
}
