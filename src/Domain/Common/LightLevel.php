<?php

declare(strict_types=1);

namespace Terrarium\Domain\Common;

enum LightLevel: string
{
    case LOW = 'low';
    case MEDIUM = 'medium';
    case BRIGHT = 'bright';
}
