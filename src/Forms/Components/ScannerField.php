<?php

namespace Lacv\PipoScanner\Forms\Components;

use Filament\Forms\Components\Field;
use Illuminate\Support\Facades\Storage;

class ScannerField extends Field
{
    protected string $view = 'pipo-scanner::components.scanner-field';

    protected string $disk = 'public';

    public function disk(string $disk): static
    {
        $this->disk = $disk;
        return $this;
    }

    public function getDisk(): string
    {
        return $this->disk ?? config('pipo-scanner.disk', 'public');
    }

    public function getExistingPath(): ?string
    {
        return $this->getState() ?: null;
    }

    public function getExistingUrl(): ?string
    {
        $path = $this->getExistingPath();
        if (! $path) return null;
        return Storage::disk($this->getDisk())->url($path);
    }
}
