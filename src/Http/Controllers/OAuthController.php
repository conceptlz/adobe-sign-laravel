<?php

declare(strict_types=1);

namespace Conceptlz\AdobeSign\Laravel\Http\Controllers;

use Conceptlz\AdobeSign\Auth\OAuthClient;
use Conceptlz\AdobeSign\Exception\OAuthException;
use Conceptlz\AdobeSign\Laravel\AdobeSignManager;
use Conceptlz\AdobeSign\Laravel\Events\OAuthConnected;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Connect the signed-in user's Acrobat Sign account. Behind ['web', 'auth'] by default.
 */
final class OAuthController
{
    private const SESSION_KEY = 'adobe_sign_oauth_state';

    public function redirect(Request $request, AdobeSignManager $manager): RedirectResponse
    {
        $state = OAuthClient::generateState();
        $request->session()->put(self::SESSION_KEY, $state);

        return new RedirectResponse($manager->oauth()->authorizationUrl(config('adobe-sign.oauth.scopes', []), $state));
    }

    public function callback(Request $request, AdobeSignManager $manager): RedirectResponse
    {
        // pull(): a state is single-use, so a replayed callback URL fails.
        $expected = (string) $request->session()->pull(self::SESSION_KEY, '');

        try {
            $token = $manager->oauth()->handleCallback($request->query(), $expected);
        } catch (OAuthException $e) {
            abort(in_array($e->errorCode, ['STATE_MISMATCH', 'MISSING_CODE'], true) ? 400 : 403, $e->getMessage());
        }

        $user = $request->user();
        if ($user === null) {
            // Only reachable if route_middleware was changed to drop "auth".
            abort(401, 'Sign in before connecting Acrobat Sign.');
        }
        $key = $manager->userKey($user);
        $manager->tokens()->put($key, $token);

        OAuthConnected::dispatch($user, $key, $token->apiAccessPoint);

        $after = config('adobe-sign.oauth.redirect_after', '/');

        return new RedirectResponse(url(is_string($after) && $after !== '' ? $after : '/'));
    }
}
