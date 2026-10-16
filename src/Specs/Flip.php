<?php

declare(strict_types=1);

namespace Folklore\Image\Specs;

enum Flip: string
{
    case Horizontal = 'horizontal';
    case Vertical = 'vertical';
    case Both = 'both';
}
