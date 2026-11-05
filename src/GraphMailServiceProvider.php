<?php

namespace MercantilePacific\GraphMail;

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
        $this->publishes([
            __DIR__.'/../config/graph-mail.php' => config_path('graph-mail.php'),
        ], 'graph-mail-config');
    }
}
