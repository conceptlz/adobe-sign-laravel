# conceptlz/adobe-sign-laravel

Thin Laravel 12–13 bridge for [`conceptlz/adobe-sign`](../adobe-sign). It adds config, a facade,
an encrypted token table, OAuth connect routes, a webhook endpoint and Laravel events. All Acrobat
Sign logic stays in the core package.

```bash
composer require conceptlz/adobe-sign-laravel
php artisan vendor:publish --tag=adobe-sign-config
php artisan vendor:publish --tag=adobe-sign-migrations && php artisan migrate   # only for OAuth
php artisan adobe-sign:verify
```

## Integration key (one account)

```dotenv
ADOBE_SIGN_INTEGRATION_KEY=3AAABL...
```

```php
use Conceptlz\AdobeSign\Laravel\Facades\AdobeSign;

$docId = AdobeSign::transientDocuments()->upload(storage_path('contract.pdf'));
$agreementId = AdobeSign::agreements()->create(
    AgreementInfo::create('NDA')->file($docId)->signers($user->email),
);

// or inject it
public function __invoke(\Conceptlz\AdobeSign\AdobeSign $sign) { ... }
```

## OAuth (each user connects their own account)

```dotenv
ADOBE_SIGN_CLIENT_ID=CBJCHB...
ADOBE_SIGN_CLIENT_SECRET=...
# optional, defaults to route('adobe-sign.oauth.callback'); register it on your Adobe app
ADOBE_SIGN_REDIRECT_URI=https://app.example.com/adobe-sign/oauth/callback
```

- Send signed-in users to `route('adobe-sign.oauth.redirect')`. The callback checks the single-use
  state, stores the tokens encrypted (APP_KEY) under the user's id, fires `OAuthConnected` and
  redirects to `oauth.redirect_after`.
- `AdobeSign::forUser($user)->agreements()->...`: tokens refresh and are written back automatically.
- `AdobeSign::isConnected($user)`
- Tokens per team instead of per user: `AdobeSignManager::resolveUserKeyUsing(fn ($u) => 'team:'.$u->current_team_id)`.
- `ADOBE_SIGN_AUTH=oauth` makes the facade/injected client use the signed-in user.

## Webhooks

`GET|POST /adobe-sign/webhook` (route `adobe-sign.webhook`) is registered outside the `web`
group, so there's no CSRF or session. It:

1. rejects deliveries whose `X-AdobeSign-ClientId` isn't in `webhooks.client_ids` (defaults to your OAuth client id),
2. answers the verification GET and every POST with the echo Adobe requires,
3. drops retried duplicates by `webhookNotificationId` (cache, 72h),
4. fires `Conceptlz\AdobeSign\Laravel\Events\WebhookReceived`.

Adobe allows 5 seconds per delivery, so make listeners queued:

```php
use Conceptlz\AdobeSign\Laravel\Events\WebhookReceived;
use Conceptlz\AdobeSign\Webhook\AgreementEvent;

class SyncAgreement implements ShouldQueue
{
    public function handle(WebhookReceived $received): void
    {
        $event = $received->event;
        if ($event instanceof AgreementEvent && $event->isWorkflowCompleted()) {
            // Payloads aren't signed: re-fetch before trusting them.
            $agreement = AdobeSign::agreements()->get($event->agreementId());
        }
    }
}
```

Register a webhook: `AdobeSign::webhooks()->create(WebhookInfo::forAgreement('Sync', route('adobe-sign.webhook'), $id)->events(EventType::AGREEMENT_ALL))`.

## Configuration notes

- Discovered data-center URLs are cached in `cache_store` (your default store if null).
- Request logs go to `log_channel` (default channel; `false` disables them). Headers and bodies are never logged.
- Bind `Psr\Http\Client\ClientInterface` in the container to swap the HTTP client (e.g. a mock in tests). Otherwise Guzzle is discovered.
- The manager is bound `scoped`, so it's safe under Octane.
