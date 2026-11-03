<?php

declare(strict_types=1);

namespace Conceptlz\AdobeSign\Laravel\Tests\Feature;

use Conceptlz\AdobeSign\AdobeSign;
use Conceptlz\AdobeSign\Auth\AccessToken;
use Conceptlz\AdobeSign\Auth\TokenStore;
use Conceptlz\AdobeSign\Laravel\AdobeSignManager;
use Conceptlz\AdobeSign\Laravel\EloquentTokenStore;
use Conceptlz\AdobeSign\Laravel\Facades\AdobeSign as AdobeSignFacade;
use Conceptlz\AdobeSign\Laravel\MisconfiguredException;
use Conceptlz\AdobeSign\Laravel\Tests\TestCase;
use Illuminate\Support\Facades\DB;

final class ClientTest extends TestCase
{
    public function test_facade_uses_integration_key_and_laravel_cache_for_the_shard(): void
    {
        $this->queue(200, 'base_uris');
        $this->queue(200, 'agreement');
        $this->queue(200, 'agreement');

        $agreement = AdobeSignFacade::agreements()->get('agr-1');

        self::assertSame('Mutual NDA', $agreement->name);
        self::assertSame('Bearer ' . self::KEY, $this->sent(1)->getHeaderLine('Authorization'));
        self::assertSame(self::API . '/agreements/agr-1', (string) $this->sent(1)->getUri());

        // Next request (fresh scoped manager, as under Octane) reuses the cached shard.
        $this->app->forgetScopedInstances();
        $this->app->make(AdobeSign::class)->agreements()->get('agr-1');

        self::assertCount(3, $this->http->getRequests(), 'no second GET /baseUris');
    }

    public function test_container_binding_and_token_store_default(): void
    {
        self::assertInstanceOf(AdobeSignManager::class, $this->app->make(AdobeSignManager::class));
        self::assertInstanceOf(EloquentTokenStore::class, $this->app->make(TokenStore::class));
    }

    public function test_missing_key_is_a_clear_error(): void
    {
        config(['adobe-sign.integration_key' => null]);
        $this->app->forgetScopedInstances();

        $this->expectException(MisconfiguredException::class);
        $this->expectExceptionMessage('ADOBE_SIGN_INTEGRATION_KEY');

        AdobeSignFacade::client();
    }

    public function test_token_store_encrypts_at_rest(): void
    {
        $store = $this->app->make(TokenStore::class);
        $store->put('7', new AccessToken('plain-access', 'plain-refresh', new \DateTimeImmutable('2026-12-01T00:00:00Z'), 'https://api.eu1.adobesign.com/'));

        $row = (array) DB::table('adobe_sign_tokens')->where('key', '7')->first();
        self::assertStringNotContainsString('plain-access', $row['access_token']);
        self::assertStringNotContainsString('plain-refresh', (string) $row['refresh_token']);

        $token = $store->get('7');
        self::assertSame('plain-access', $token?->accessToken);
        self::assertSame('plain-refresh', $token?->refreshToken);
        self::assertSame('2026-12-01T00:00:00+00:00', $token?->expiresAt?->format(DATE_ATOM));
        self::assertSame('https://api.eu1.adobesign.com/', $token?->apiAccessPoint);
        self::assertNull($store->get('8'));
    }

    public function test_for_user_refreshes_and_persists(): void
    {
        $this->app->make(TokenStore::class)->put('7', new AccessToken('old', 'refresh-7', new \DateTimeImmutable('-1 hour'), 'https://api.eu1.adobesign.com/'));
        $this->queue(200, 'oauth_refresh');
        $this->queue(200, 'agreement');

        AdobeSignFacade::forUser($this->user(7))->agreements()->get('agr-1');

        self::assertSame('https://api.eu1.adobesign.com/oauth/v2/refresh', (string) $this->sent(0)->getUri());
        self::assertSame('https://api.eu1.adobesign.com/api/rest/v6/agreements/agr-1', (string) $this->sent(1)->getUri());
        self::assertSame('3AAABLblqZhRefreshedAccessToken*', $this->app->make(TokenStore::class)->get('7')?->accessToken);
    }

    public function test_oauth_mode_default_client_is_the_signed_in_user(): void
    {
        config(['adobe-sign.auth' => 'oauth']);
        $this->app->forgetScopedInstances();
        $this->app->make(TokenStore::class)->put('9', new AccessToken('user-9-token', 'r', new \DateTimeImmutable('+1 hour'), 'https://api.na1.adobesign.com/'));
        $this->queue(200, 'agreement');

        $this->actingAs($this->user(9));
        $this->app->make(AdobeSign::class)->agreements()->get('agr-1');

        self::assertSame('Bearer user-9-token', $this->sent(0)->getHeaderLine('Authorization'));
        self::assertTrue(AdobeSignFacade::isConnected($this->user(9)));
        self::assertFalse(AdobeSignFacade::isConnected($this->user(10)));
    }

    public function test_custom_user_key_resolver(): void
    {
        AdobeSignManager::resolveUserKeyUsing(fn ($user) => 'team:' . $user->getAuthIdentifier());

        try {
            self::assertSame('team:7', AdobeSignFacade::userKey($this->user(7)));
        } finally {
            AdobeSignManager::resolveUserKeyUsing(null);
        }
    }

    public function test_verify_command(): void
    {
        $this->queue(200, 'base_uris');
        $this->queue(200, 'user_me');

        $this->artisan('adobe-sign:verify')
            ->expectsOutputToContain('Connected to Acrobat Sign')
            ->expectsOutputToContain('admin@example.com')
            ->assertSuccessful();
    }

    public function test_verify_command_reports_bad_credentials(): void
    {
        $this->queue(401, '{"code":"INVALID_ACCESS_TOKEN","message":"Access token provided is invalid or has expired."}');

        $this->artisan('adobe-sign:verify')
            ->expectsOutputToContain('INVALID_ACCESS_TOKEN')
            ->assertFailed();
    }
}
