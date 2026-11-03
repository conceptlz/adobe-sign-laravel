<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Jobs\DownloadSignedAgreement;
use Conceptlz\AdobeSign\Laravel\Events\WebhookReceived;
use Conceptlz\AdobeSign\Webhook\AgreementEvent;
use Conceptlz\AdobeSign\Webhook\EventType;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

/**
 * Example: react to Acrobat Sign webhook notifications.
 *
 * Queued (ShouldQueue): the package already acknowledged Adobe within its 5-second limit, so
 * the work here can take as long as it needs. Laravel 11+ discovers listeners in app/Listeners
 * automatically; otherwise register it with Event::listen(WebhookReceived::class, ...).
 */
final class ProcessAdobeSignWebhook implements ShouldQueue
{
    public function handle(WebhookReceived $received): void
    {
        $event = $received->event;

        if (!$event instanceof AgreementEvent) {
            return; // web form, Send in Bulk or library events: handle them here if you subscribe to them
        }

        Log::info('Acrobat Sign: ' . $event->event, [
            'agreement' => $event->agreementId(),
            'by' => $event->actingUserEmail,
        ]);

        match (true) {
            // Every participant has acted: fetch and keep the signed PDF.
            $event->isWorkflowCompleted() => DownloadSignedAgreement::dispatch((string) $event->agreementId()),

            // One signer finished; others may still be pending.
            $event->is(EventType::AGREEMENT_ACTION_COMPLETED) => null,

            // Declined or cancelled: update your records / notify someone.
            $event->is(EventType::AGREEMENT_REJECTED, EventType::AGREEMENT_RECALLED, EventType::AGREEMENT_EXPIRED) => null,

            default => null,
        };
    }
}
