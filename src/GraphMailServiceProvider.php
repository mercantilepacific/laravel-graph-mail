<?php

namespace MercantilePacific\GraphMail;

use Illuminate\Support\Facades\Mail;
use Illuminate\Support\ServiceProvider;

class GraphMailServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/graph-mail.php', 'graph-mail');

        $this->app->singleton(GraphMailManager::class);
        $this->app->bind(GraphMailClient::class, fn ($app): GraphMailClient => $app->make(GraphMailManager::class)->connection());
    }

    public function boot(): void
    {
        Mail::extend('graph', fn (array $config): GraphTransport => new GraphTransport(
            $this->app->make(GraphMailManager::class)->connection($config['connection'] ?? null),
        ));

        $this->publishes([
            __DIR__.'/../config/graph-mail.php' => config_path('graph-mail.php'),
        ], 'graph-mail-config');
    }
}
