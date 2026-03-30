<?php

namespace Lacv\PipoScanner\Forms\Components;

use Filament\Forms\Components\Field;
use Illuminate\Support\Facades\Storage;

class ScannerField extends Field
{
    protected string $view = 'pipo-scanner::components.scanner-field';

    protected string $disk = 'public';

    protected ?string $directory = null;

    protected ?int $maxFileSize = null;

    protected int $height = 580;

    public function disk(string $disk): static
    {
        $this->disk = $disk;
        return $this;
    }

    public function getDisk(): string
    {
        return $this->disk ?? config('pipo-scanner.disk', 'public');
    }

    /**
     * Override the upload directory for this field.
     * Defaults to config('pipo-scanner.directory').
     */
    public function directory(string $directory): static
    {
        $this->directory = $directory;
        return $this;
    }

    public function getDirectory(): string
    {
        return $this->directory ?? config('pipo-scanner.directory', 'documents/scanner/temp');
    }

    /**
     * Maximum upload size in bytes for this field.
     * Defaults to config('pipo-scanner.max_file_size').
     */
    public function maxFileSize(int $bytes): static
    {
        $this->maxFileSize = $bytes;
        return $this;
    }

    public function getMaxFileSize(): int
    {
        return $this->maxFileSize ?? config('pipo-scanner.max_file_size', 4 * 1024 * 1024);
    }

    /**
     * Height of the scanner panel in pixels.
     * Default: 580.
     */
    public function height(int $px): static
    {
        $this->height = $px;
        return $this;
    }

    public function getHeight(): int
    {
        return $this->height;
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
