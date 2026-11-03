<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Conceptlz\AdobeSign\AdobeSign;
use Conceptlz\AdobeSign\Builder\AgreementInfo;
use Conceptlz\AdobeSign\Builder\FileInfo;
use Conceptlz\AdobeSign\Builder\ParticipantSetInfo;
use Conceptlz\AdobeSign\Builder\WebhookInfo;
use Conceptlz\AdobeSign\Exception\NotFoundException;
use Conceptlz\AdobeSign\Webhook\EventType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Example: send an uploaded PDF for signature, check its status, and get a signing link.
 *
 *     Route::middleware('auth')->group(function () {
 *         Route::post('/agreements', [AgreementController::class, 'store']);
 *         Route::get('/agreements/{agreementId}', [AgreementController::class, 'show']);
 *         Route::get('/agreements/{agreementId}/sign', [AgreementController::class, 'signingUrl']);
 *         Route::get('/agreements/{agreementId}/pdf', [AgreementController::class, 'download']);
 *     });
 *
 * The AdobeSign client is injected: with ADOBE_SIGN_AUTH=integration_key it's the account-wide
 * client; for per-user OAuth use AdobeSign::forUser($request->user()) instead.
 */
final class AgreementController
{
    /**
     * POST /agreements: multipart form with `contract` (PDF), `name`, and `signers[]` in signing order.
     * Signers listed in `parallel[]` sign at the same step as the signer before them.
     */
    public function store(Request $request, AdobeSign $sign): JsonResponse
    {
        $data = $request->validate([
            'contract' => ['required', 'file', 'mimes:pdf', 'max:25600'],
            'name' => ['required', 'string', 'max:255'],
            'signers' => ['required', 'array', 'min:1'],
            'signers.*' => ['required', 'email'],
            'parallel' => ['sometimes', 'array'],
            'parallel.*' => ['email'],
        ]);

        // 1. Upload the PDF (streamed, never fully loaded into memory).
        $file = $request->file('contract');
        $documentId = $sign->transientDocuments()->upload(
            $file->getRealPath(),
            fileName: $file->getClientOriginalName(),
            mimeType: 'application/pdf',
        );

        // 2. Build the signing order: each signer is the next step, unless marked parallel.
        $agreement = AgreementInfo::create($data['name'])
            ->file(FileInfo::transientDocument($documentId))
            ->message('Please review and sign.');

        $parallel = $data['parallel'] ?? [];
        $order = 0;
        foreach ($data['signers'] as $email) {
            $order = in_array($email, $parallel, true) && $order > 0 ? $order : $order + 1;
            $agreement->participantSet(ParticipantSetInfo::signer($email)->order($order));
        }

        // 3. Create it in IN_PROCESS state (the default) = sent to the first signer(s) now.
        $agreementId = $sign->agreements()->create($agreement);

        // 4. Ask Adobe to notify our webhook endpoint about this agreement (one webhook per
        //    agreement is Adobe's recommendation). The package registers the route.
        $sign->webhooks()->create(
            WebhookInfo::forAgreement('Agreement ' . $agreementId, route('adobe-sign.webhook'), $agreementId)
                ->events(EventType::AGREEMENT_ALL),
        );

        return new JsonResponse(['agreement_id' => $agreementId], 201);
    }

    /**
     * GET /agreements/{agreementId}: current status, e.g. OUT_FOR_SIGNATURE or SIGNED.
     */
    public function show(string $agreementId, AdobeSign $sign): JsonResponse
    {
        $agreement = $sign->agreements()->get($agreementId);

        return new JsonResponse([
            'id' => $agreement->id,
            'name' => $agreement->name,
            'status' => $agreement->status,
        ]);
    }

    /**
     * GET /agreements/{agreementId}/sign: redirect the signer to their e-sign page (embedded signing).
     */
    public function signingUrl(Request $request, string $agreementId, AdobeSign $sign): JsonResponse
    {
        $user = $request->user() ?? abort(401);

        try {
            $url = $sign->agreements()->signingUrlFor($agreementId, $user->email);
        } catch (NotFoundException $e) {
            // Right after creation Adobe may still be processing the PDF (AGREEMENT_NOT_SIGNABLE).
            return new JsonResponse(['message' => 'Not ready for signing yet, try again shortly.'], 409);
        }

        return $url === null
            ? new JsonResponse(['message' => "It isn't your turn to sign."], 403)
            : new JsonResponse(['url' => $url]);
    }

    /**
     * GET /agreements/{agreementId}/pdf: stream the current PDF straight from Adobe to the browser.
     */
    public function download(string $agreementId, AdobeSign $sign): StreamedResponse
    {
        $pdf = $sign->agreements()->combinedDocument($agreementId);

        return new StreamedResponse(function () use ($pdf) {
            while (!$pdf->eof()) {
                echo $pdf->read(65536);
            }
        }, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $agreementId . '.pdf"',
        ]);
    }
}
