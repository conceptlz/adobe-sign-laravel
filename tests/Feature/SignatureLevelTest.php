<?php

declare(strict_types=1);

namespace Conceptlz\AdobeSign\Laravel\Tests\Feature;

use Conceptlz\AdobeSign\Builder\AgreementInfo;
use Conceptlz\AdobeSign\Enum\SignatureLevel;
use Conceptlz\AdobeSign\Laravel\Facades\AdobeSign;
use Conceptlz\AdobeSign\Laravel\MisconfiguredException;
use Conceptlz\AdobeSign\Laravel\Tests\TestCase;

final class SignatureLevelTest extends TestCase
{
    public function test_env_default_level_reaches_the_request(): void
    {
        config(['adobe-sign.signature_level' => 'qes']);
        $this->app->forgetScopedInstances();
        $this->queue(200, 'base_uris');
        $this->queue(200, 'agreement_created');

        AdobeSign::agreements()->create(AgreementInfo::create('Contract')->file('tdoc')->signers('a@example.com'));

        $sent = json_decode((string) $this->sent(1)->getBody(), true);
        self::assertTrue($sent['participantSetsInfo'][0]['isDigitalSignatureRequired']);
        self::assertSame(SignatureLevel::QES, AdobeSign::signatureLevel());
    }

    public function test_default_is_ses(): void
    {
        self::assertSame(SignatureLevel::SES, AdobeSign::signatureLevel());
    }

    public function test_invalid_level_is_a_clear_configuration_error(): void
    {
        config(['adobe-sign.signature_level' => 'gold']);
        $this->app->forgetScopedInstances();

        $this->expectException(MisconfiguredException::class);
        $this->expectExceptionMessage('ADOBE_SIGN_SIGNATURE_LEVEL must be ses, aes or qes');

        AdobeSign::client();
    }

    public function test_shortfalls_use_configured_level_and_qualified_issuers(): void
    {
        config(['adobe-sign.signature_level' => 'qes', 'adobe-sign.qualified_issuers' => ['TrustPro QTSP']]);
        $this->app->forgetScopedInstances();
        $this->queue(200, 'base_uris');
        $this->queue(200, '{"id":"agr-1","name":"x","status":"OUT_FOR_SIGNATURE","signatureType":"ESIGN","participantSetsInfo":[]}');
        $this->queue(200, 'evidence_members');
        $this->queue(200, 'evidence_events');

        $shortfalls = AdobeSign::signatureShortfalls('agr-1');

        self::assertSame(
            [
                'Jane@Client.example signed with an electronic signature (TYPED), not a digital one: SES, QES required.',
                'ceo@vendor.example has not signed yet.',
            ],
            $shortfalls,
            'the CFO signed with the configured QTSP, so only Jane and the CEO fall short',
        );
    }
}
