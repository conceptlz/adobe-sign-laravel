<?php

declare(strict_types=1);

namespace Conceptlz\AdobeSign\Laravel\Events;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A user finished the OAuth flow; their tokens are stored under $key.
 */
final class OAuthConnected
{
    use Dispatchable;

    public function __construct(
        public readonly Authenticatable $user,
        public readonly string $key,
        /** The account's API host, e.g. https://api.eu1.adobesign.com/ */
        public readonly ?string $apiAccessPoint,
    ) {
    }
}
