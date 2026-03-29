# Pipo Scanner

A professional document scanner component for Filament v3/v5. Capture documents using the device camera, auto-detect edges with OpenCV.js, crop, apply filters, and save as PDF — all from the browser.

## Features

- 📷 Camera capture with auto edge detection
- ✂️ Manual corner adjustment with magnifier loupe
- 🎨 Filters: color, grayscale, black & white
- 🔄 Rotation and horizontal flip
- 📄 Multi-page PDF support
- 📁 Merge with existing documents
- 📱 Mobile friendly

## Requirements

- PHP 8.1+
- Laravel 10/11/12
- Filament 3.x or 5.x
- Livewire 3.x

## Installation

```bash
composer require lacv/pipo-scanner
```

Publish the assets (required):

```bash
php artisan vendor:publish --tag=pipo-scanner-assets
```

Optionally publish the config:

```bash
php artisan vendor:publish --tag=pipo-scanner-config
```

## Register the Plugin

In your `AdminPanelProvider`:

```php
use Lacv\PipoScanner\PipoScannerPlugin;

public function panel(Panel $panel): Panel
{
    return $panel
        ->plugin(PipoScannerPlugin::make());
}
```

## Usage in a Filament Form

Add the scanner component inside any Filament form field using a `ViewField` or directly in a Blade section:

```blade
<x-pipo-scanner::scanner
    :existing-path="$this->record?->document_path"
    :existing-url="$this->record?->document_path ? asset('storage/' . $this->record->document_path) : null"
/>
```

The component dispatches a `scanner:saved` browser event with `{ path, url }` when the document is uploaded. Listen to it with Alpine.js or Livewire:

```js
$wire.on('scanner:saved', ({ path }) => {
    $wire.set('document_path', path);
});
```

## Configuration

```php
// config/pipo-scanner.php
return [
    'disk'         => 'public',
    'directory'    => 'documents/scanner/temp',
    'max_file_size' => 4 * 1024 * 1024, // 4MB
    'upload_route' => '/pipo-scanner/upload',
];
```

## License

MIT
