<?php

declare(strict_types=1);

namespace Conceptlz\AdobeSign\Laravel;

use Conceptlz\AdobeSign\AdobeSign;
use Conceptlz\AdobeSign\Auth\TokenStore;
use Conceptlz\AdobeSign\Laravel\Console\VerifyCommand;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the core SDK into Laravel: config, the manager, the AdobeSign binding, the token store,
 * routes, publishable config/migration, and the adobe-sign:verify command.
 */
final class AdobeSignServiceProvider extends ServiceProvider
{
    /**
     * AdobeSignManager is scoped; resolving AdobeSign returns the manager's default client;
     * TokenStore defaults to EloquentTokenStore unless the app already bound one.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/adobe-sign.php', 'adobe-sign');

        $this->app->scoped(AdobeSignManager::class, fn ($app) => new AdobeSignManager($app, $app['config']['adobe-sign']));
        $this->app->bind(AdobeSign::class, fn ($app) => $app->make(AdobeSignManager::class)->client());
        $this->app->bindIf(TokenStore::class, EloquentTokenStore::class, true);
    }

    /**
     * Load the OAuth and webhook routes (each can be disabled in config); in the console, also
     * register the publishable config and migration and the verify command.
     */
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
