<?php

declare(strict_types=1);

namespace Conceptlz\AdobeSign\Laravel\Facades;

use Conceptlz\AdobeSign\Laravel\AdobeSignManager;
use Illuminate\Support\Facades\Facade;

/**
 * @method static \Conceptlz\AdobeSign\AdobeSign client()
 * @method static \Conceptlz\AdobeSign\AdobeSign forUser(\Illuminate\Contracts\Auth\Authenticatable|string|int $user)
 * @method static bool isConnected(\Illuminate\Contracts\Auth\Authenticatable|string|int $user)
 * @method static \Conceptlz\AdobeSign\Auth\OAuthClient oauth()
 * @method static \Conceptlz\AdobeSign\Webhook\WebhookHandler webhookHandler()
 * @method static void resolveUserKeyUsing(?callable $resolver)
 * @method static \Conceptlz\AdobeSign\AdobeSign asUser(string $user)
 * @method static \Conceptlz\AdobeSign\Resource\Agreements agreements()
 * @method static \Conceptlz\AdobeSign\Resource\TransientDocuments transientDocuments()
 * @method static \Conceptlz\AdobeSign\Resource\LibraryDocuments libraryDocuments()
 * @method static \Conceptlz\AdobeSign\Resource\Webhooks webhooks()
 * @method static \Conceptlz\AdobeSign\Resource\Users users()
 * @method static \Conceptlz\AdobeSign\Resource\Widgets widgets()
 * @method static \Conceptlz\AdobeSign\Resource\MegaSigns megaSigns()
 *
 * @see AdobeSignManager
 */
class AdobeSign extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return AdobeSignManager::class;
    }
}
