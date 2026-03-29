<?php

namespace Lacv\PipoScanner;

use Filament\Contracts\Plugin;
use Filament\Panel;

class PipoScannerPlugin implements Plugin
{
    public function getId(): string
    {
        return 'pipo-scanner';
    }

    public function register(Panel $panel): void
    {
        //
    }

    public function boot(Panel $panel): void
    {
        //
    }

    public static function make(): static
    {
        return app(static::class);
    }
}
