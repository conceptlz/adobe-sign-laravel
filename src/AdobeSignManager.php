<?php

declare(strict_types=1);

namespace Conceptlz\AdobeSign\Laravel;

use Conceptlz\AdobeSign\AdobeSign;
use Conceptlz\AdobeSign\Auth\OAuthClient;
use Conceptlz\AdobeSign\Auth\OAuthConfig;
use Conceptlz\AdobeSign\Auth\OAuthTokenProvider;
use Conceptlz\AdobeSign\Auth\TokenStore;
use Conceptlz\AdobeSign\ClientOptions;
use Conceptlz\AdobeSign\Enum\SignatureLevel;
use Conceptlz\AdobeSign\Webhook\WebhookHandler;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Container\Container;
use Psr\Http\Client\ClientInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Builds configured AdobeSign clients. Bound "scoped", so under Octane it's rebuilt per request.
 *
 * Calls it doesn't define go to the default client: AdobeSign::agreements() works via the facade.
 *
 * @mixin AdobeSign
 */
class AdobeSignManager
{
    private ?AdobeSign $default = null;

    /** @var array<string, AdobeSign> */
    private array $users = [];

    private ?OAuthClient $oauth = null;

    /** @var (\Closure(Authenticatable): string)|null */
    private static ?\Closure $userKeyResolver = null;

    /**
     * @param array<string, mixed> $config the adobe-sign config array
     */
    public function __construct(
        private readonly Container $app,
        private readonly array $config,
    ) {
    }

    /**
     * The default client: the integration key, or the signed-in user's OAuth connection.
     *
     * @throws MisconfiguredException when the key is missing, or auth is "oauth" and nobody is
     *                                signed in or OAuth isn't configured
     */
    public function client(): AdobeSign
    {
        if (($this->config['auth'] ?? 'integration_key') === 'oauth') {
            $user = $this->app->make('auth')->user();
            if ($user === null) {
                throw new MisconfiguredException('adobe-sign.auth is "oauth" but no user is signed in; use AdobeSign::forUser($user).');
            }

            return $this->forUser($user);
        }

        $key = (string) ($this->config['integration_key'] ?? '');
        if ($key === '') {
            throw new MisconfiguredException('Set ADOBE_SIGN_INTEGRATION_KEY, or ADOBE_SIGN_AUTH=oauth.');
        }

        return $this->default ??= AdobeSign::withIntegrationKey($key, ...$this->dependencies());
    }

    /**
     * A client acting with the OAuth tokens the given user connected (refreshed automatically).
     * Cached per user key for the lifetime of the manager. A user who never connected only
     * fails on the first API call, with OAuthException (NO_TOKEN); check isConnected() first.
     *
     * @param Authenticatable|string|int $user a user, or a key as produced by userKey()
     * @throws MisconfiguredException when OAuth client id/secret are not configured
     */
    public function forUser(Authenticatable|string|int $user): AdobeSign
    {
        $key = $this->userKey($user);

        return $this->users[$key] ??= new AdobeSign(
            new OAuthTokenProvider($this->oauth(), $this->tokens(), $key),
            ...$this->dependencies(),
        );
    }

    /** Whether a token is stored for this user (it may still be expired or revoked). */
    public function isConnected(Authenticatable|string|int $user): bool
    {
        return $this->tokens()->get($this->userKey($user)) !== null;
    }

    /**
     * The OAuth client for the authorization-code flow. The redirect URI defaults to the
     * package's adobe-sign.oauth.callback route.
     *
     * @throws MisconfiguredException when the client id or secret is not set
     */
    public function oauth(): OAuthClient
    {
        if ($this->oauth !== null) {
            return $this->oauth;
        }

        $oauth = $this->config['oauth'] ?? [];
        if (empty($oauth['client_id']) || empty($oauth['client_secret'])) {
            throw new MisconfiguredException('Set ADOBE_SIGN_CLIENT_ID and ADOBE_SIGN_CLIENT_SECRET to use OAuth.');
        }

        $redirect = $oauth['redirect_uri'] ?? null;
        if (empty($redirect)) {
            $redirect = $this->app->make('url')->route('adobe-sign.oauth.callback');
        }

        return $this->oauth = new OAuthClient(
            new OAuthConfig((string) $oauth['client_id'], (string) $oauth['client_secret'], (string) $redirect),
            $this->httpClient(),
        );
    }

    /** The bound TokenStore (EloquentTokenStore by default). */
    public function tokens(): TokenStore
    {
        return $this->app->make(TokenStore::class);
    }

    /**
     * A WebhookHandler allowlisting webhooks.client_ids, or oauth.client_id when that is empty.
     *
     * @throws MisconfiguredException when neither is set
     */
    public function webhookHandler(): WebhookHandler
    {
        $ids = $this->config['webhooks']['client_ids'] ?? [];
        if ($ids === [] && !empty($this->config['oauth']['client_id'])) {
            $ids = [(string) $this->config['oauth']['client_id']];
        }
        if ($ids === []) {
            throw new MisconfiguredException('Set ADOBE_SIGN_WEBHOOK_CLIENT_IDS (or ADOBE_SIGN_CLIENT_ID) to receive webhooks.');
        }

        return new WebhookHandler(array_values($ids));
    }

    /**
     * The TokenStore key for a user: strings and ints are used as-is, users go through the
     * resolveUserKeyUsing() callback or default to their auth identifier.
     */
    public function userKey(Authenticatable|string|int $user): string
    {
        if (!$user instanceof Authenticatable) {
            return (string) $user;
        }

        return self::$userKeyResolver !== null
            ? (self::$userKeyResolver)($user)
            : (string) $user->getAuthIdentifier();
    }

    /**
     * Change how users map to token rows, e.g. per team: fn ($user) => 'team:'.$user->current_team_id
     * Process-wide (static); pass null to restore the default.
     *
     * @param (callable(Authenticatable): string)|null $resolver
     */
    public static function resolveUserKeyUsing(?callable $resolver): void
    {
        self::$userKeyResolver = $resolver !== null ? \Closure::fromCallable($resolver) : null;
    }

    /**
     * Forward to the default client, e.g. agreements().
     *
     * @param array<mixed> $arguments
     */
    public function __call(string $method, array $arguments): mixed
    {
        return $this->client()->{$method}(...$arguments);
    }

    /**
     * The project's default signature level (config adobe-sign.signature_level).
     *
     * @throws MisconfiguredException for a value other than ses, aes or qes
     */
    public function signatureLevel(): SignatureLevel
    {
        $value = strtoupper(trim((string) ($this->config['signature_level'] ?? 'ses')));

        return SignatureLevel::tryFrom($value)
            ?? throw new MisconfiguredException(sprintf('ADOBE_SIGN_SIGNATURE_LEVEL must be ses, aes or qes; got "%s".', $value));
    }

    /**
     * Certificate issuers accepted as qualified (config adobe-sign.qualified_issuers).
     *
     * @return list<string>
     */
    public function qualifiedIssuers(): array
    {
        return array_values(array_map('strval', $this->config['qualified_issuers'] ?? []));
    }

    /**
     * Whether a completed agreement met the project's signature level, using the configured qualified issuers.
     *
     * @return list<string> shortfalls; empty when every signer reached the level
     */
    public function signatureShortfalls(string $agreementId, ?SignatureLevel $required = null): array
    {
        return $this->client()->agreements()->signatureEvidence($agreementId)
            ->shortfalls($required ?? $this->signatureLevel(), $this->qualifiedIssuers());
    }

    /**
     * Named constructor arguments shared by every client.
     *
     * @return array<string, mixed>
     */
    private function dependencies(): array
    {
        $deps = [
            'cache' => $this->app->make('cache')->store($this->config['cache_store'] ?? null),
            'logger' => $this->logger(),
            'options' => new ClientOptions(
                bootstrapUrl: (string) ($this->config['bootstrap_url'] ?? 'https://api.adobesign.com'),
                baseUriTtl: (int) ($this->config['base_uri_ttl'] ?? 86400),
                maxRetries: (int) ($this->config['retries']['max'] ?? 3),
                maxRetryDelay: (float) ($this->config['retries']['max_delay_seconds'] ?? 60),
                defaultSignatureLevel: $this->signatureLevel(),
            ),
        ];

        if (($client = $this->httpClient()) !== null) {
            $deps['httpClient'] = $client;
        }

        return $deps;
    }

    /**
     * A PSR-18 client bound in the container wins (handy for tests); otherwise discovery
     * finds Guzzle, which every Laravel app has.
     */
    private function httpClient(): ?ClientInterface
    {
        return $this->app->bound(ClientInterface::class) ? $this->app->make(ClientInterface::class) : null;
    }

    /** The configured log channel; log_channel false disables logging. */
    private function logger(): LoggerInterface
    {
        $channel = $this->config['log_channel'] ?? null;
        if ($channel === false) {
            return new NullLogger();
        }

        $log = $this->app->make('log');

        return $channel === null ? $log : $log->channel($channel);
    }
}
