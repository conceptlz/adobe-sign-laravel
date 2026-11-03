<?php

declare(strict_types=1);

namespace Conceptlz\AdobeSign\Laravel\Tests\Feature;

use Conceptlz\AdobeSign\Auth\TokenStore;
use Conceptlz\AdobeSign\Laravel\Events\OAuthConnected;
use Conceptlz\AdobeSign\Laravel\Events\WebhookReceived;
use Conceptlz\AdobeSign\Laravel\Tests\TestCase;
use Conceptlz\AdobeSign\Webhook\AgreementEvent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;

final class RoutesTest extends TestCase
{
    // --- OAuth ------------------------------------------------------------

    public function test_redirect_sends_user_to_adobe_with_session_state(): void
    {
        $response = $this->actingAs($this->user())->get('/adobe-sign/oauth/redirect');

        $response->assertRedirect();
        $location = $response->headers->get('Location');
        self::assertStringStartsWith('https://secure.adobesign.com/public/oauth/v2?', $location);

        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        self::assertSame(self::CLIENT_ID, $query['client_id']);
        self::assertSame(route('adobe-sign.oauth.callback'), $query['redirect_uri']);
        self::assertStringContainsString('agreement_read:self', $query['scope']);
        self::assertSame(session('adobe_sign_oauth_state'), $query['state']);
    }

    public function test_redirect_requires_login(): void
    {
        $this->get('/adobe-sign/oauth/redirect', ['Accept' => 'application/json'])->assertUnauthorized();
    }

    public function test_callback_stores_tokens_for_the_user_and_fires_event(): void
    {
        Event::fake([OAuthConnected::class]);
        $this->queue(200, 'oauth_token');

        $this->actingAs($this->user(7))
            ->withSession(['adobe_sign_oauth_state' => 'state-1'])
            ->get('/adobe-sign/oauth/callback?code=auth-code&state=state-1&api_access_point=https://api.eu1.adobesign.com/')
            ->assertRedirect('/');

        self::assertSame('https://api.eu1.adobesign.com/oauth/v2/token', (string) $this->sent(0)->getUri());
        parse_str((string) $this->sent(0)->getBody(), $form);
        self::assertSame(route('adobe-sign.oauth.callback'), $form['redirect_uri']);

        $token = $this->app->make(TokenStore::class)->get('7');
        self::assertSame('3AAABLblqZhAccessTokenFromCodeExchange*', $token?->accessToken);
        Event::assertDispatched(OAuthConnected::class, fn (OAuthConnected $e) => $e->key === '7' && $e->apiAccessPoint === 'https://api.eu1.adobesign.com/');
    }

    public function test_callback_rejects_forged_or_replayed_state(): void
    {
        $this->actingAs($this->user())
            ->withSession(['adobe_sign_oauth_state' => 'state-1'])
            ->get('/adobe-sign/oauth/callback?code=c&state=attacker')
            ->assertStatus(400);

        // state was pulled from the session on the first attempt
        $this->actingAs($this->user())
            ->get('/adobe-sign/oauth/callback?code=c&state=state-1')
            ->assertStatus(400);

        self::assertSame([], $this->http->getRequests());
    }

    public function test_callback_when_user_denies(): void
    {
        $this->actingAs($this->user())
            ->withSession(['adobe_sign_oauth_state' => 's'])
            ->get('/adobe-sign/oauth/callback?error=ACCESS_DENIED&error_description=nope&state=s')
            ->assertForbidden();
    }

    // --- Webhooks ---------------------------------------------------------

    public function test_webhook_verification_get(): void
    {
        $this->get('/adobe-sign/webhook', ['X-ADOBESIGN-CLIENTID' => self::CLIENT_ID])
            ->assertOk()
            ->assertHeader('X-AdobeSign-ClientId', self::CLIENT_ID)
            ->assertExactJson(['xAdobeSignClientId' => self::CLIENT_ID]);
    }

    public function test_webhook_post_dispatches_once_per_notification(): void
    {
        Event::fake([WebhookReceived::class]);

        foreach ([1, 2] as $_) { // Adobe retry of the same notification
            $this->call('POST', '/adobe-sign/webhook', [], [], [], [
                'HTTP_X_ADOBESIGN_CLIENTID' => self::CLIENT_ID,
                'CONTENT_TYPE' => 'application/json',
            ], self::fixture('webhook_agreement_workflow_completed'))
                ->assertOk()
                ->assertHeader('X-AdobeSign-ClientId', self::CLIENT_ID);
        }

        Event::assertDispatchedTimes(WebhookReceived::class, 1);
        Event::assertDispatched(WebhookReceived::class, fn (WebhookReceived $e) => $e->event instanceof AgreementEvent
            && $e->event->isWorkflowCompleted()
            && $e->event->agreementId() === 'CBJCHBCAABAAx1aFQyYF-aGrLsBZtVqaT9gPh6Xw42kK');
    }

    public function test_webhook_rejects_unknown_client(): void
    {
        Event::fake([WebhookReceived::class]);

        $this->call('POST', '/adobe-sign/webhook', [], [], [], ['HTTP_X_ADOBESIGN_CLIENTID' => 'someone-else'], self::fixture('webhook_agreement_workflow_completed'))
            ->assertForbidden()
            ->assertHeaderMissing('X-AdobeSign-ClientId');

        Event::assertNotDispatched(WebhookReceived::class);
    }

    public function test_webhook_unreadable_body_is_400(): void
    {
        $this->call('POST', '/adobe-sign/webhook', [], [], [], ['HTTP_X_ADOBESIGN_CLIENTID' => self::CLIENT_ID], '{nope')
            ->assertStatus(400);
    }

    public function test_webhook_route_is_outside_the_web_group(): void
    {
        $middleware = Route::getRoutes()->getByName('adobe-sign.webhook')?->gatherMiddleware() ?? ['missing'];

        self::assertNotContains('web', $middleware, 'Adobe cannot send a CSRF token or session cookie');
    }

    public function test_explicit_webhook_client_ids_override_oauth_client_id(): void
    {
        config(['adobe-sign.webhooks.client_ids' => ['other-app']]);
        $this->app->forgetScopedInstances();

        $this->get('/adobe-sign/webhook', ['X-AdobeSign-ClientId' => self::CLIENT_ID])->assertForbidden();
        $this->get('/adobe-sign/webhook', ['X-AdobeSign-ClientId' => 'other-app'])->assertOk();
    }
}
