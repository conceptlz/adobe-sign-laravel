<?php

declare(strict_types=1);

namespace Conceptlz\AdobeSign\Laravel\Events;

use Conceptlz\AdobeSign\Webhook\WebhookEvent;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * An Acrobat Sign webhook notification arrived (duplicates already dropped).
 *
 * Adobe gives the endpoint 5 seconds: make listeners implement ShouldQueue for any real work.
 */
final class WebhookReceived
{
    use Dispatchable;

    public function __construct(public readonly WebhookEvent $event)
    {
    }
}
