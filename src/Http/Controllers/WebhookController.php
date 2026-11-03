<?php

declare(strict_types=1);

namespace Conceptlz\AdobeSign\Laravel\Http\Controllers;

use Conceptlz\AdobeSign\Exception\WebhookException;
use Conceptlz\AdobeSign\Laravel\AdobeSignManager;
use Conceptlz\AdobeSign\Laravel\Events\WebhookReceived;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Verification GET + notification POST endpoint. Acknowledges with the client id echo Adobe
 * requires, drops retried duplicates, and fires WebhookReceived.
 */
final class WebhookController
{
    /**
     * 403 for a missing or unknown client id, 400 for an unreadable POST body, otherwise 200 with
     * the echo.
     * Dedupe keys live in the adobe-sign.cache_store store for webhooks.dedupe_ttl seconds.
     */
    public function __invoke(Request $request, AdobeSignManager $manager, CacheFactory $cache): Response|JsonResponse
    {
        $handler = $manager->webhookHandler();

        try {
            $clientId = $handler->verifyClientId($request->header($handler::CLIENT_ID_HEADER));
        } catch (WebhookException $e) {
            Log::warning('Rejected Acrobat Sign webhook', ['reason' => $e->getMessage(), 'ip' => $request->ip()]);

            return new Response('', 403);
        }

        if ($request->isMethod('POST')) {
            try {
                $event = $handler->parse($request->getContent());
            } catch (WebhookException $e) {
                Log::error('Unreadable Acrobat Sign webhook', ['reason' => $e->getMessage()]);

                return new Response('', 400); // Adobe retries; nothing is silently lost
            }

            $store = $cache->store(config('adobe-sign.cache_store'));
            $first = $event->notificationId === null
                || $store->add('adobe_sign.webhook.' . $event->notificationId, true, (int) config('adobe-sign.webhooks.dedupe_ttl', 259200));

            if ($first) {
                WebhookReceived::dispatch($event);
            }
        }

        return new JsonResponse(
            [$handler::CLIENT_ID_BODY_KEY => $clientId],
            200,
            [$handler::CLIENT_ID_HEADER => $clientId],
        );
    }
}
