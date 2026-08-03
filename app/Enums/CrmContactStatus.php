<?php

namespace App\Enums;

enum CrmContactStatus: string
{
    case Active = 'active';
    case Anonymized = 'anonymized';
}
