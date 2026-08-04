<?php

namespace App\Enums;

enum CrmOrderAttributionStatus: string
{
    case Pending = 'pending';
    case Attributed = 'attributed';
    case Unattributable = 'unattributable';
}
