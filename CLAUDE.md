# conceptlz/adobe-sign-laravel (bridge)

Thin Laravel 12–13 layer over `conceptlz/adobe-sign`. Read the monorepo root `CLAUDE.md` first.

**Rule:** API behaviour belongs in the core package. This package only wires the core into Laravel:
config, container bindings, routes, persistence, events. If a change needs new API logic, make it in
`packages/adobe-sign` and expose it here.

## Pieces

| File | Role |
| --- | --- |
| `config/adobe-sign.php` | Auth mode (`integration_key` / `oauth`), OAuth app, signature level, qualified issuers, cache store, retries, log channel, webhook path and client ids |
| `src/AdobeSignServiceProvider.php` | Binds `AdobeSignManager` and `AdobeSign`, loads routes, publishes config and migration, registers `adobe-sign:verify` |
| `src/AdobeSignManager.php` | Builds clients: `client()`, `forUser($user)` (OAuth), `isConnected()`, `oauth()`, `webhookHandler()`, `signatureLevel()`, `qualifiedIssuers()`, `signatureShortfalls()`; forwards other calls to the client |
| `src/Facades/AdobeSign.php` | Facade over the manager. Keep its `@method` annotations in sync when the manager or client changes |
| `src/EloquentTokenStore.php`, `src/Models/AdobeSignToken.php`, `database/migrations/*` | OAuth token persistence (`adobe_sign_tokens`) |
| `src/Http/Controllers/OAuthController.php` | Connect/callback routes; fires `Events\OAuthConnected` |
| `src/Http/Controllers/WebhookController.php` | Answers Adobe's verification handshake, dedupes deliveries, fires `Events\WebhookReceived` |
| `routes/adobe-sign.php` | OAuth and webhook routes, each switchable in config |
| `examples/` | Controller, queued webhook listener, download job, routes: copy-paste starting points |

## Environment variables

`ADOBE_SIGN_AUTH`, `ADOBE_SIGN_INTEGRATION_KEY`, `ADOBE_SIGN_CLIENT_ID`, `ADOBE_SIGN_CLIENT_SECRET`,
`ADOBE_SIGN_REDIRECT_URI`, `ADOBE_SIGN_SIGNATURE_LEVEL` (ses/aes/qes), `ADOBE_SIGN_QUALIFIED_ISSUERS`,
`ADOBE_SIGN_BOOTSTRAP_URL`, `ADOBE_SIGN_CACHE_STORE`, `ADOBE_SIGN_LOG_CHANNEL`,
`ADOBE_SIGN_WEBHOOK_CLIENT_IDS`. A new config key needs an entry in `config/adobe-sign.php`, the README's
Configuration section, and a test.

## Testing

```bash
composer install && composer test     # Orchestra Testbench feature tests
composer analyse                      # Larastan, level 8
```

Tests in `tests/Feature` fake HTTP through the core client; they never call Adobe. CI runs Laravel 12
(Testbench 10) and 13 (Testbench 11).

## Dependency on the core

The bridge requires `conceptlz/adobe-sign` `^0.3`. Locally it resolves to `../adobe-sign` through a path
repository pinned to `0.3.x-dev`. Bump both on every minor release (see Releasing in the root
`CLAUDE.md`).
