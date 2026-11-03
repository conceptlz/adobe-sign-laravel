<?php

declare(strict_types=1);

namespace Conceptlz\AdobeSign\Laravel\Http\Controllers;

use Conceptlz\AdobeSign\Auth\OAuthClient;
use Conceptlz\AdobeSign\Exception\InvalidArgumentException;
use Conceptlz\AdobeSign\Exception\OAuthException;
use Conceptlz\AdobeSign\Exception\TransportException;
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

    /**
     * GET {prefix}/redirect: store a fresh state in the session and send the user to Adobe with
     * the configured scopes.
     */
    public function redirect(Request $request, AdobeSignManager $manager): RedirectResponse
    {
        $state = OAuthClient::generateState();
        $request->session()->put(self::SESSION_KEY, $state);

        return new RedirectResponse($manager->oauth()->authorizationUrl(config('adobe-sign.oauth.scopes', []), $state));
    }

    /**
     * GET {prefix}/callback: exchange the code, store the token under the user's key, fire
     * OAuthConnected and redirect to oauth.redirect_after. Aborts with 400 for a bad state or
     * missing code and 403 when Adobe refused.
     */
    public function callback(Request $request, AdobeSignManager $manager): RedirectResponse
    {
        // pull(): a state is single-use, so a replayed callback URL fails.
        $expected = (string) $request->session()->pull(self::SESSION_KEY, '');

        try {
            $token = $manager->oauth()->handleCallback($request->query(), $expected);
        } catch (OAuthException $e) {
            abort(in_array($e->errorCode, ['STATE_MISMATCH', 'MISSING_CODE'], true) ? 400 : 403, $e->getMessage());
        } catch (InvalidArgumentException $e) {
            // e.g. a forged api_access_point outside Adobe's domains: the request itself is bad.
            abort(400, $e->getMessage());
        } catch (TransportException) {
            abort(502, 'Acrobat Sign could not be reached. Please try again.');
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
