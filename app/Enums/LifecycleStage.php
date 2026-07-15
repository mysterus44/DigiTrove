<?php

namespace App\Enums;

enum LifecycleStage: string
{
    case Lead = 'lead';
    case Prospect = 'prospect';
    case Customer = 'customer';
    case Repeat = 'repeat';
    case Churned = 'churned';
}
