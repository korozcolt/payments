<?php

declare(strict_types=1);

namespace Korbytes\Payments;

use GuzzleHttp\Psr7\HttpFactory;
use Illuminate\Support\ServiceProvider;
use Korbytes\Payments\Console\Commands\ProcessDueSubscriptionsCommand;
use Korbytes\Payments\Drivers\DriverContext;
use Korbytes\Payments\Http\HttpClient;
use Korbytes\Payments\Support\EloquentPayoutRepository;
use Korbytes\Payments\Support\EloquentSubscriptionRepository;
use Korbytes\Payments\Support\EloquentTransactionRepository;
use Korbytes\Payments\Support\LaravelConfigProvider;
use Korbytes\Payments\Support\LaravelEventBridge;
use Korbytes\Payments\Support\LaravelHttpClient;
use Korbytes\Payments\Support\LaravelLogger;
use Korbytes\Payments\Support\LaravelTransactionRunner;
use Korbytes\Payments\Support\SystemClock;

class PaymentsServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/payments.php',
            'payments'
        );

        // Wires the framework-agnostic core (packages/core) to Laravel.
        $this->app->singleton(DriverContext::class, function () {
            $factory = new HttpFactory;

            return new DriverContext(
                http: new HttpClient(new LaravelHttpClient, $factory, $factory),
                logger: new LaravelLogger,
                settings: new LaravelConfigProvider,
                transactions: new EloquentTransactionRepository,
                subscriptions: new EloquentSubscriptionRepository,
                payouts: new EloquentPayoutRepository,
                runner: new LaravelTransactionRunner,
                events: new LaravelEventBridge,
                clock: new SystemClock,
            );
        });

        $this->app->singleton('payments', function ($app) {
            return new PaymentManager;
        });

        $this->app->alias('payments', PaymentManager::class);
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        $this->registerPublishing();
        $this->registerRoutes();
        $this->registerCommands();
    }

    /**
     * Register the package's Artisan commands.
     *
     * Registering the command does NOT schedule it — the host application
     * must add it to its own scheduler for subscriptions to actually be
     * charged. See USAGE.md.
     */
    protected function registerCommands(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                ProcessDueSubscriptionsCommand::class,
            ]);
        }
    }

    /**
     * Register the package's publishable resources.
     */
    protected function registerPublishing(): void
    {
        if ($this->app->runningInConsole()) {
            // Config
            $this->publishes([
                __DIR__.'/../config/payments.php' => config_path('payments.php'),
            ], 'payments-config');

            // Migrations
            $this->publishes([
                __DIR__.'/../database/migrations/' => database_path('migrations'),
            ], 'payments-migrations');

            // All
            $this->publishes([
                __DIR__.'/../config/payments.php' => config_path('payments.php'),
                __DIR__.'/../database/migrations/' => database_path('migrations'),
            ], 'payments');

            // Load migrations
            $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        }
    }

    /**
     * Register the package routes.
     */
    protected function registerRoutes(): void
    {
        if (config('payments.routes.enabled', true)) {
            $this->loadRoutesFrom(__DIR__.'/../routes/webhooks.php');
        }
    }
}
