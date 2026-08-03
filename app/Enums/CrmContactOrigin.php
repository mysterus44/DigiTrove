<?php

namespace App\Enums;

enum CrmContactOrigin: string
{
    case GuestOrder = 'guest_order';
    case VerifiedAccount = 'verified_account';
}
