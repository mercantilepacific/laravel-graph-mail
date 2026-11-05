<?php

namespace MercantilePacific\GraphMail\Tests;

use MercantilePacific\GraphMail\GraphMailServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [GraphMailServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('graph-mail.connections.default', [
            'tenant_id' => 'tenant',
            'client_id' => 'client',
            'client_secret' => 'secret',
            'mailbox' => 'box@example.com',
            'mail_folder' => 'inbox',
        ]);
    }
}
