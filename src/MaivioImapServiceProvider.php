<?php

declare(strict_types=1);

namespace Maivio\Imap;

use Illuminate\Support\ServiceProvider;
use Maivio\Imap\Parser\MimeParser;

class MaivioImapServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/maivio-imap.php', 'maivio-imap');

        $this->app->singleton(MimeParser::class, fn () => new MimeParser);

        $this->app->singleton(ImapManager::class, function ($app): ImapManager {
            $config = (array) $app['config']->get('maivio-imap', []);
            $parser = $app->make(MimeParser::class);

            return new ImapManager($config, $parser);
        });

        $this->app->alias(ImapManager::class, 'maivio.imap');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/maivio-imap.php' => config_path('maivio-imap.php'),
            ], 'maivio-imap-config');
        }
    }
}
