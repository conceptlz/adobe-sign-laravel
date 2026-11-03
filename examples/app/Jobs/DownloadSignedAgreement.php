<?php

declare(strict_types=1);

namespace App\Jobs;

use Conceptlz\AdobeSign\AdobeSign;
use Conceptlz\AdobeSign\Exception\RateLimitException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Storage;

/**
 * Example: download a completed agreement's signed PDF (with audit report) into your storage.
 */
final class DownloadSignedAgreement implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 5;

    public function __construct(public readonly string $agreementId)
    {
    }

    public function handle(AdobeSign $sign): void
    {
        try {
            // Webhook payloads aren't signed, so confirm the status with the API before acting on it.
            $agreement = $sign->agreements()->get($this->agreementId);
            if (!$agreement->isSigned()) {
                return;
            }

            // A PSR-7 stream: written to storage in chunks, never held in memory as a whole.
            $pdf = $sign->agreements()->combinedDocument($this->agreementId, attachAuditReport: true);
        } catch (RateLimitException $e) {
            $this->release($e->retryAfter ?? 60);

            return;
        }

        $resource = $pdf->detach() ?? throw new \RuntimeException('Download stream is not readable.');

        Storage::disk(config('filesystems.default'))->writeStream("agreements/{$this->agreementId}.pdf", $resource);
    }
}
