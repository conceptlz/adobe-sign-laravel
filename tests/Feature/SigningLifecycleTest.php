<?php

declare(strict_types=1);

namespace Conceptlz\AdobeSign\Laravel\Tests\Feature;

use App\Http\Controllers\AgreementController;
use App\Listeners\ProcessAdobeSignWebhook;
use Conceptlz\AdobeSign\Laravel\Events\WebhookReceived;
use Conceptlz\AdobeSign\Laravel\Tests\TestCase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Nyholm\Psr7\Response;

/**
 * Runs the example app code in examples/ end to end: upload a PDF through a form, send it with a
 * signing order, receive Adobe's webhook, and end up with the signed PDF in Laravel storage.
 */
final class SigningLifecycleTest extends TestCase
{
    private const AGREEMENT_ID = 'CBJCHBCAABAAx1aFQyYF-aGrLsBZtVqaT9gPh6Xw42kK';

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('queue.default', 'sync');
        $app['config']->set('filesystems.default', 'local');
    }

    protected function defineRoutes($router): void
    {
        Route::post('/agreements', [AgreementController::class, 'store']);
    }

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Event::listen(WebhookReceived::class, ProcessAdobeSignWebhook::class);

        // Adobe only calls HTTPS URLs and WebhookInfo rejects anything else. In production the
        // app's public https:// root comes from APP_URL / trusted proxies; force it here.
        URL::forceRootUrl('https://app.example.com');
        URL::forceScheme('https');
    }

    public function test_upload_send_in_order_then_webhook_saves_signed_pdf(): void
    {
        $pdf = (string) file_get_contents(__DIR__ . '/../Fixtures/sample.pdf');

        // Sending: baseUris -> upload -> create agreement -> register webhook.
        $this->queue(200, 'base_uris');
        $this->queue(200, 'transient_document');
        $this->queue(200, 'agreement_created');
        $this->queue(200, '{"id":"CBJCHBCAABAAwebhook1"}');

        $this->post('/agreements', [
            'contract' => UploadedFile::fake()->createWithContent('msa.pdf', $pdf),
            'name' => 'Master Services Agreement',
            'signers' => ['jane@client.example', 'legal@vendor.example', 'ceo@vendor.example'],
            'parallel' => ['legal@vendor.example'], // signs alongside Jane
        ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertExactJson(['agreement_id' => self::AGREEMENT_ID]);

        $upload = $this->sent(1);
        self::assertStringEndsWith('/transientDocuments', $upload->getUri()->getPath());
        self::assertStringContainsString($pdf, (string) $upload->getBody());
        self::assertStringContainsString('filename="msa.pdf"', (string) $upload->getBody());

        $create = json_decode((string) $this->sent(2)->getBody(), true);
        self::assertSame('IN_PROCESS', $create['state']);
        self::assertSame(
            [[1, 'jane@client.example'], [1, 'legal@vendor.example'], [2, 'ceo@vendor.example']],
            array_map(static fn (array $s) => [$s['order'], $s['memberInfos'][0]['email']], $create['participantSetsInfo']),
        );

        $webhook = json_decode((string) $this->sent(3)->getBody(), true);
        self::assertSame('https://app.example.com/adobe-sign/webhook', $webhook['webhookUrlInfo']['url']);
        self::assertSame(self::AGREEMENT_ID, $webhook['resourceId']);

        // Signing finished: Adobe posts AGREEMENT_WORKFLOW_COMPLETED; the listener queues the
        // download job (sync queue here), which confirms SIGNED and stores the PDF.
        $this->queue(200, 'agreement_signed');
        $this->http->addResponse(new Response(200, ['Content-Type' => 'application/pdf'], $pdf));

        $this->call('POST', '/adobe-sign/webhook', [], [], [], [
            'HTTP_X_ADOBESIGN_CLIENTID' => self::CLIENT_ID,
            'CONTENT_TYPE' => 'application/json',
        ], self::fixture('webhook_agreement_workflow_completed'))
            ->assertOk()
            ->assertHeader('X-AdobeSign-ClientId', self::CLIENT_ID);

        self::assertStringEndsWith('/agreements/' . self::AGREEMENT_ID, $this->sent(4)->getUri()->getPath());
        self::assertSame('attachAuditReport=true', $this->sent(5)->getUri()->getQuery());

        Storage::disk('local')->assertExists('agreements/' . self::AGREEMENT_ID . '.pdf');
        self::assertSame($pdf, Storage::disk('local')->get('agreements/' . self::AGREEMENT_ID . '.pdf'));
    }

    public function test_upload_must_be_a_pdf(): void
    {
        $this->post('/agreements', [
            'contract' => UploadedFile::fake()->create('notes.txt', 1, 'text/plain'),
            'name' => 'x',
            'signers' => ['a@example.com'],
        ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('contract');

        self::assertSame([], $this->http->getRequests(), 'nothing is sent to Adobe');
    }
}
