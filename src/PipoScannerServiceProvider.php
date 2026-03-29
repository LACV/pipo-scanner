<?php

namespace Lacv\PipoScanner;

use Illuminate\Support\ServiceProvider;

class PipoScannerServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'pipo-scanner');
        $this->mergeConfigFrom(__DIR__ . '/../config/pipo-scanner.php', 'pipo-scanner');

        $this->publishes([
            __DIR__ . '/../resources/views' => resource_path('views/vendor/pipo-scanner'),
        ], 'pipo-scanner-views');

        $this->publishes([
            __DIR__ . '/../resources/js/jscanify.js' => public_path('vendor/pipo-scanner/jscanify.js'),
        ], 'pipo-scanner-assets');

        $this->publishes([
            __DIR__ . '/../config/pipo-scanner.php' => config_path('pipo-scanner.php'),
        ], 'pipo-scanner-config');

        $this->loadRoutesFrom(__DIR__ . '/../routes/web.php');
    }

    public function register(): void
    {
        //
    }
}
