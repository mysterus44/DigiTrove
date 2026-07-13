<?php

namespace App\Enums;

enum ProductType: string
{
    case Software = 'software';
    case Course = 'course';
    case Ebook = 'ebook';
    case Bundle = 'bundle';
    case Template = 'template';
}
