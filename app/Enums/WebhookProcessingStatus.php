<?php

namespace App\Enums;

enum WebhookProcessingStatus: string
{
    case Received = 'received';
    case Processed = 'processed';
    case Ignored = 'ignored';
    case Failed = 'failed';
}
