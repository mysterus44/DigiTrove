<?php

namespace App\Enums;

enum CrmOrderAttributionReason: string
{
    case InvalidEmailContract = 'invalid_email_contract';
    case AttributionConflict = 'attribution_conflict';
}
