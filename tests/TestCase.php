<?php

declare(strict_types=1);

namespace Conceptlz\AdobeSign\Laravel\Tests;

use Conceptlz\AdobeSign\Laravel\AdobeSignServiceProvider;
use Http\Mock\Client as MockClient;
use Illuminate\Auth\GenericUser;
use Nyholm\Psr7\Response;
use Orchestra\Testbench\TestCase as BaseTestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;

abstract class TestCase extends BaseTestCase
{
    protected const KEY = 'test-integration-key';
    protected const CLIENT_ID = 'CBJCHBCAABAAclientid';
    protected const API = 'https://api.na4.adobesign.com/api/rest/v6';

    protected MockClient $http;

    protected function setUp(): void
    {
        parent::setUp();

        $this->http = new MockClient();
        $this->http->setDefaultException(new \LogicException('Unexpected request: no queued response left.'));
        $this->app->instance(ClientInterface::class, $this->http);
    }

    protected function getPackageProviders($app): array
    {
        return [AdobeSignServiceProvider::class];
    }

    protected function getPackageAliases($app): array
    {
        return ['AdobeSign' => \Conceptlz\AdobeSign\Laravel\Facades\AdobeSign::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('adobe-sign.integration_key', self::KEY);
        $app['config']->set('adobe-sign.oauth.client_id', self::CLIENT_ID);
        $app['config']->set('adobe-sign.oauth.client_secret', 'test-secret');
        $app['config']->set('adobe-sign.log_channel', false);
        $app['config']->set('cache.default', 'array');
        $app['config']->set('database.default', 'testing');
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
    }

    protected function queue(int $status, ?string $body = null, array $headers = []): void
    {
        $contents = '';
        if ($body !== null) {
            $file = __DIR__ . '/Fixtures/' . $body . '.json';
            $contents = is_file($file) ? (string) file_get_contents($file) : $body;
            $headers += ['Content-Type' => 'application/json'];
        }
        $this->http->addResponse(new Response($status, $headers, $contents));
    }

    protected function sent(int $index): RequestInterface
    {
        $requests = $this->http->getRequests();
        self::assertArrayHasKey($index, $requests, "No request #$index was sent.");

        return $requests[$index];
    }

    protected function user(int $id = 7): GenericUser
    {
        return new GenericUser(['id' => $id, 'name' => 'Jane', 'remember_token' => null]);
    }

    protected static function fixture(string $name): string
    {
        return (string) file_get_contents(__DIR__ . '/Fixtures/' . $name . '.json');
    }
}
