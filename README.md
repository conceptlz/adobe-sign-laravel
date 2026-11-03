# conceptlz/adobe-sign-laravel

Adobe Acrobat Sign for Laravel 12 and 13: send PDFs for e-signature, follow signing through
webhooks, and store the signed documents.

This is the Laravel bridge for [`conceptlz/adobe-sign`](https://github.com/conceptlz/adobe-sign).
It adds:
- config and `.env` keys
- a facade, and dependency injection of the client
- OAuth connect routes with encrypted token storage
- a ready-made webhook endpoint that fires Laravel events
- an `adobe-sign:verify` command

All Acrobat Sign features, including the builders, resources and models, come from the core
package; its README is the API reference.

## Contents

- [Installation](#installation)
- [Configuration](#configuration)
- [Walkthrough: send a PDF and store the signed copy](#walkthrough-send-a-pdf-and-store-the-signed-copy)
- [Using the client](#using-the-client)
- [OAuth: users connect their own Acrobat Sign accounts](#oauth-users-connect-their-own-acrobat-sign-accounts)
- [Webhooks](#webhooks)
- [Testing your application](#testing-your-application)
- [Troubleshooting](#troubleshooting)

## Installation

```bash
composer require conceptlz/adobe-sign-laravel
php artisan vendor:publish --tag=adobe-sign-config        # optional: config/adobe-sign.php
```

For OAuth only, add the token table:

```bash
php artisan vendor:publish --tag=adobe-sign-migrations
php artisan migrate
```

Check your credentials:

```bash
php artisan adobe-sign:verify
```

The service provider and the `AdobeSign` facade are auto-discovered. Guzzle, already part of
every Laravel app, is used as the HTTP client.

## Configuration

`.env` keys (only the first one is needed for Integration Key use):

| Key | Purpose |
| --- | --- |
| `ADOBE_SIGN_INTEGRATION_KEY` | the account's Integration Key (Acrobat Sign → Account → Acrobat Sign API → API Information) |
| `ADOBE_SIGN_AUTH` | `integration_key` (default) or `oauth`: what the facade and injected client use |
| `ADOBE_SIGN_CLIENT_ID`, `ADOBE_SIGN_CLIENT_SECRET` | your API application's OAuth credentials |
| `ADOBE_SIGN_REDIRECT_URI` | defaults to `route('adobe-sign.oauth.callback')`; must be registered on your Adobe app |
| `ADOBE_SIGN_SCOPES` | space-separated; default `user_login:self agreement_read:self agreement_write:self agreement_send:self webhook_read:self webhook_write:self` |
| `ADOBE_SIGN_WEBHOOK_CLIENT_IDS` | comma-separated client ids allowed to call your webhook; defaults to `ADOBE_SIGN_CLIENT_ID` |
| `ADOBE_SIGN_CACHE_STORE` | cache store for the account's data-center URL (default store if empty) |
| `ADOBE_SIGN_LOG_CHANNEL` | log channel for request logs (default channel; set `false` in config to disable) |
| `ADOBE_SIGN_BOOTSTRAP_URL` | only for Gov Cloud and similar |

Everything else (route paths, middleware, retries, the token table name, the webhook dedupe window)
is in `config/adobe-sign.php`, with comments.

## Walkthrough: send a PDF and store the signed copy

Complete, working versions of these classes are in [`examples/`](examples). The package's test
suite runs them end to end: upload through a form, signing order, sending, a webhook from Adobe,
and the signed PDF landing in storage.

### 1. A controller that uploads and sends

From [`examples/app/Http/Controllers/AgreementController.php`](examples/app/Http/Controllers/AgreementController.php):

```php
use Conceptlz\AdobeSign\AdobeSign;
use Conceptlz\AdobeSign\Builder\{AgreementInfo, FileInfo, ParticipantSetInfo, WebhookInfo};
use Conceptlz\AdobeSign\Webhook\EventType;

public function store(Request $request, AdobeSign $sign): JsonResponse
{
    $request->validate([
        'contract' => ['required', 'file', 'mimes:pdf'],
        'signers' => ['required', 'array'],
        'signers.*' => ['email'],
    ]);

    // 1. Upload the PDF (streamed)
    $file = $request->file('contract');
    $documentId = $sign->transientDocuments()->upload($file->getRealPath(), $file->getClientOriginalName());

    // 2. Signing order: signers in the order given
    $agreement = AgreementInfo::create('Contract')->file(FileInfo::transientDocument($documentId));
    foreach ($request->input('signers') as $i => $email) {
        $agreement->participantSet(ParticipantSetInfo::signer($email)->order($i + 1));
    }

    // 3. Create = send (state IN_PROCESS is the default)
    $agreementId = $sign->agreements()->create($agreement);

    // 4. Have Adobe call this app's webhook about this agreement
    $sign->webhooks()->create(
        WebhookInfo::forAgreement("Agreement $agreementId", route('adobe-sign.webhook'), $agreementId)
            ->events(EventType::AGREEMENT_ALL),
    );

    return response()->json(['agreement_id' => $agreementId], 201);
}
```

Two signers who should sign **in parallel** get the same `order()` number; the example controller
supports this with a `parallel[]` field. Order numbers must run 1, 2, 3… with no gaps, and the
builder rejects anything else before calling Adobe.

Mixing sequential and parallel steps (e.g. one signer, then two at once) is **hybrid routing**, an
Acrobat Sign account setting. When it's off, Adobe answers `403 HYBRID_ROUTING_NOT_ALLOWED`.

The example also has `show()` for status, `signingUrl()` for embedded signing, and `download()`,
which streams the PDF to the browser. Routes are in [`examples/routes/web.php`](examples/routes/web.php).

### 2. A queued listener for webhook events

The package's webhook route answers Adobe, then fires
`Conceptlz\AdobeSign\Laravel\Events\WebhookReceived`. From
[`examples/app/Listeners/ProcessAdobeSignWebhook.php`](examples/app/Listeners/ProcessAdobeSignWebhook.php):

```php
use App\Jobs\DownloadSignedAgreement;
use Conceptlz\AdobeSign\Laravel\Events\WebhookReceived;
use Conceptlz\AdobeSign\Webhook\AgreementEvent;

final class ProcessAdobeSignWebhook implements ShouldQueue
{
    public function handle(WebhookReceived $received): void
    {
        $event = $received->event;

        if ($event instanceof AgreementEvent && $event->isWorkflowCompleted()) {
            DownloadSignedAgreement::dispatch($event->agreementId());
        }
    }
}
```

Laravel 11+ discovers listeners in `app/Listeners` automatically. Otherwise register it with
`Event::listen(WebhookReceived::class, ProcessAdobeSignWebhook::class)`.

### 3. A job that downloads and stores the signed PDF

From [`examples/app/Jobs/DownloadSignedAgreement.php`](examples/app/Jobs/DownloadSignedAgreement.php):

```php
public function handle(AdobeSign $sign): void
{
    // Webhook payloads aren't signed: confirm with the API first.
    if (!$sign->agreements()->get($this->agreementId)->isSigned()) {
        return;
    }

    $pdf = $sign->agreements()->combinedDocument($this->agreementId, attachAuditReport: true);

    // Streamed into any disk (local, S3, ...) without loading the PDF into memory.
    Storage::writeStream("agreements/{$this->agreementId}.pdf", $pdf->detach());
}
```

That's the whole loop: **upload → signing order → send → webhook → signed PDF in your storage.**

## Using the client

```php
use Conceptlz\AdobeSign\AdobeSign;
use Conceptlz\AdobeSign\Laravel\Facades\AdobeSign as Sign;

// Facade
Sign::agreements()->status($agreementId);

// Injection (controllers, jobs, commands): the default client per ADOBE_SIGN_AUTH
public function __invoke(AdobeSign $sign) { $sign->agreements()->get($id); }

// Another user in the account (needs an account-admin key)
Sign::asUser('jane@example.com')->agreements()->all();
```

Everything on the client (`agreements()`, `transientDocuments()`, `libraryDocuments()`, `webhooks()`,
`users()`, `widgets()`, `megaSigns()`) is documented in the
[core README](https://github.com/conceptlz/adobe-sign#readme).

## OAuth: users connect their own Acrobat Sign accounts

1. **Set it up.** Create an API application in Acrobat Sign, set `ADOBE_SIGN_CLIENT_ID` and
   `ADOBE_SIGN_CLIENT_SECRET`, run the migration, and register this redirect URI on the Adobe
   application:

   ```text
   https://your-app.example.com/adobe-sign/oauth/callback
   ```

2. **Link signed-in users to the redirect route.** It sits behind `['web', 'auth']`:

   ```blade
   <a href="{{ route('adobe-sign.oauth.redirect') }}">Connect Acrobat Sign</a>
   ```

   The callback checks the single-use state and stores the tokens (encrypted with `APP_KEY`) under
   the user's id. It then fires `Conceptlz\AdobeSign\Laravel\Events\OAuthConnected` and redirects to
   `oauth.redirect_after`.

3. **Act as that user.** Tokens refresh automatically and are saved back:

   ```php
   if (Sign::isConnected($user)) {
       Sign::forUser($user)->agreements()->create($info);
   }
   ```

- **Signed-in user by default.** `ADOBE_SIGN_AUTH=oauth` makes the facade and injected client use the
  signed-in user.
- **Tokens per team instead of per user.** Call
  `AdobeSignManager::resolveUserKeyUsing(fn ($user) => 'team:'.$user->current_team_id)` in a service
  provider.
- **Checking one user's connection:** `php artisan adobe-sign:verify --user=42`.

## Webhooks

The package registers `GET|POST /adobe-sign/webhook` (route name `adobe-sign.webhook`). It sits
outside the `web` middleware group, so CSRF protection and sessions don't get in Adobe's way.

On each call it:
1. Rejects callers whose `X-AdobeSign-ClientId` isn't allowlisted (`ADOBE_SIGN_WEBHOOK_CLIENT_IDS`,
   defaulting to your OAuth client id).
2. Answers Adobe's verification `GET` and every `POST` with the client-id echo Adobe requires.
3. Drops duplicate deliveries (Adobe retries) by notification id, remembered for 72 hours in the cache.
4. Fires `WebhookReceived` with a typed event (`AgreementEvent`, `MegaSignEvent`, `WidgetEvent`,
   `LibraryDocumentEvent` or `UnknownEvent`).

**Things that matter in production:**

- **Make listeners queued (`ShouldQueue`).** Adobe allows 5 seconds per delivery. After 72 hours of
  failures it disables the webhook, and notifications sent while it's disabled are lost.
- **The webhook URL must be public HTTPS.** `route('adobe-sign.webhook')` builds the URL from the
  current request or `APP_URL`. Behind a load balancer or proxy, configure
  [trusted proxies](https://laravel.com/docs/requests#configuring-trusted-proxies) so it comes out
  as `https://`. `WebhookInfo` rejects `http://` URLs, because Adobe wouldn't call them.
- **Local development** needs a tunnel such as `ngrok http 8000` or `cloudflared tunnel`. Set
  `APP_URL` to the tunnel's https address.
- **Don't trust payloads blindly.** They aren't signed. Re-fetch the agreement before acting, as the
  example job does.

Events you'll usually care about (`Conceptlz\AdobeSign\Webhook\EventType`):
- `AGREEMENT_WORKFLOW_COMPLETED`: everyone signed
- `AGREEMENT_ACTION_COMPLETED`: one participant finished
- `AGREEMENT_REJECTED`, `AGREEMENT_RECALLED`, `AGREEMENT_EXPIRED`
- `AGREEMENT_EMAIL_BOUNCED`

## Testing your application

Bind a mock [PSR-18](https://www.php-fig.org/psr/psr-18/) client and the package uses it instead of
Guzzle:

```php
use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Response;
use Psr\Http\Client\ClientInterface;

$http = new MockClient();
$http->addResponse(new Response(200, ['Content-Type' => 'application/json'],
    '{"apiAccessPoint":"https://api.na1.adobesign.com/","webAccessPoint":"https://secure.na1.adobesign.com/"}'));
$http->addResponse(new Response(200, ['Content-Type' => 'application/json'], '{"id":"agr-1"}'));
$this->app->instance(ClientInterface::class, $http);

// ... call your code, then assert on $http->getRequests()
```

The first request a fresh client sends is always `GET /baseUris`. Your webhook code can be tested
without Adobe by posting to the route:

```php
Event::fake([WebhookReceived::class]);

$this->postJson('/adobe-sign/webhook', $payload, ['X-AdobeSign-ClientId' => config('adobe-sign.oauth.client_id')])
    ->assertOk()
    ->assertHeader('X-AdobeSign-ClientId');

Event::assertDispatched(WebhookReceived::class);
```

## Troubleshooting

| Symptom | Cause / fix |
| --- | --- |
| `AuthenticationException ... INVALID_ACCESS_TOKEN` | wrong or revoked Integration Key; check with `php artisan adobe-sign:verify` |
| `MisconfiguredException` | a required `.env` key is missing; the message names it |
| `NotFoundException ... AGREEMENT_NOT_SIGNABLE` right after creating | Adobe is still processing the PDF; retry after a few seconds |
| `InvalidArgumentException: Acrobat Sign only delivers webhooks to HTTPS URLs` | `route()` produced `http://`: set `APP_URL` / trusted proxies |
| Webhook creation fails | Adobe couldn't reach your URL, or the reply had no client-id echo: check that it's public HTTPS and not behind auth middleware |
| Webhook was working, then stopped | Adobe disabled it after 72h of failed deliveries; re-enable with `Sign::webhooks()->updateState($id, 'ACTIVE')` |
| `OAuthException ... EXPIRED_TOKEN` | the refresh token expired (60 days unused) or was revoked; the user must connect again |

## License

MIT. See [LICENSE](LICENSE).
