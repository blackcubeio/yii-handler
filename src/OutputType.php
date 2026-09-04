<?php

declare(strict_types=1);

/**
 * OutputType.php
 *
 * PHP Version 8.4
 *
 * @copyright 2010-2026 Blackcube - Philippe Gaultier
 * @license https://www.blackcube.io/license
 * @link https://www.blackcube.io
 */

namespace Blackcube\Handler;

enum OutputType: string
{
    case Render = 'render';
    case Partial = 'partial';
    case Json = 'json';
    case Redirect = 'redirect';
    case Download = 'download';
}
