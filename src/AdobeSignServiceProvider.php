<?php

declare(strict_types=1);

namespace Conceptlz\AdobeSign\Laravel;

use Conceptlz\AdobeSign\AdobeSign;
use Conceptlz\AdobeSign\Auth\TokenStore;
use Conceptlz\AdobeSign\Laravel\Console\VerifyCommand;
use Illuminate\Support\ServiceProvider;

final class AdobeSignServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/adobe-sign.php', 'adobe-sign');

        $this->app->scoped(AdobeSignManager::class, fn ($app) => new AdobeSignManager($app, $app['config']['adobe-sign']));
        $this->app->bind(AdobeSign::class, fn ($app) => $app->make(AdobeSignManager::class)->client());
        $this->app->bindIf(TokenStore::class, EloquentTokenStore::class, true);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__ . '/../routes/adobe-sign.php');

        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__ . '/../config/adobe-sign.php' => config_path('adobe-sign.php')], 'adobe-sign-config');
            $this->publishesMigrations([__DIR__ . '/../database/migrations' => database_path('migrations')], 'adobe-sign-migrations');
            $this->commands([VerifyCommand::class]);
        }
    }
}
