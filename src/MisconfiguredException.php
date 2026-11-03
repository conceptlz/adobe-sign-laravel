<?php

declare(strict_types=1);

namespace Conceptlz\AdobeSign\Laravel;

use Conceptlz\AdobeSign\Exception\AdobeSignException;

/**
 * The adobe-sign config is missing something the requested feature needs.
 */
class MisconfiguredException extends \RuntimeException implements AdobeSignException
{
}
