<?php

namespace App\Enums;

enum MessageStatus: string
{
    case Complete = 'complete';
    case Partial = 'partial';
}
