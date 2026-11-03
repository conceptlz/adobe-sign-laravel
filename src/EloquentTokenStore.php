<?php

declare(strict_types=1);

namespace Conceptlz\AdobeSign\Laravel;

use Conceptlz\AdobeSign\Auth\AccessToken;
use Conceptlz\AdobeSign\Auth\TokenStore;
use Conceptlz\AdobeSign\Laravel\Models\AdobeSignToken;

/**
 * Core TokenStore backed by the adobe_sign_tokens table, tokens encrypted with APP_KEY.
 */
final class EloquentTokenStore implements TokenStore
{
    public function get(string $key): ?AccessToken
    {
        $row = AdobeSignToken::query()->where('key', $key)->first();

        return $row === null ? null : new AccessToken(
            $row->access_token,
            $row->refresh_token,
            $row->expires_at?->toDateTimeImmutable(),
            $row->api_access_point,
        );
    }

    public function put(string $key, AccessToken $token): void
    {
        AdobeSignToken::query()->updateOrCreate(['key' => $key], [
            'access_token' => $token->accessToken,
            'refresh_token' => $token->refreshToken,
            'expires_at' => $token->expiresAt,
            'api_access_point' => $token->apiAccessPoint,
        ]);
    }

    public function forget(string $key): void
    {
        AdobeSignToken::query()->where('key', $key)->delete();
    }
}
