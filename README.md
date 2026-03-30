# Pipo Scanner

A professional document scanner component for **Filament v3/v5**, powered by [OpenCV.js](https://opencv.org/) and [jscanify](https://github.com/ColonelParrot/jscanify).

Capture documents directly from the device camera, auto-detect edges, adjust corners, apply filters, and save as a multi-page PDF — all from the browser, with zero native dependencies.

> 📸 Screenshots and demo GIF coming soon.

---

## Features

- 📷 **Camera capture** with real-time automatic edge detection
- ✂️ **Manual corner adjustment** with magnifier loupe for pixel-perfect crops
- 🎨 **Three filters**: color, grayscale, black & white
- 🔄 **Rotation** (90° steps) and **horizontal flip**
- 📄 **Multi-page PDF** — scan multiple pages into a single document
- 📁 **Edit mode** — open an existing document and add or replace pages
- 📱 **Mobile-first** — works on Android and iOS browsers (requires HTTPS)
- 🔒 **Secure upload** — base64 PDF sent via authenticated POST, stored on any Laravel disk

---

## Requirements

| Dependency | Version |
|---|---|
| PHP | ^8.1 |
| Laravel | ^10.0 \| ^11.0 \| ^12.0 \| ^13.0 |
| Filament | ^3.0 \| ^5.0 |
| Livewire | ^3.0 |

> **Note:** Camera access requires **HTTPS** (or `localhost`). Plain HTTP over LAN will not expose `navigator.mediaDevices` in most browsers.

---

## Installation

**1. Install via Composer:**

```bash
composer require lacv/pipo-scanner
```

**2. Publish the JS asset (required):**

```bash
php artisan vendor:publish --tag=pipo-scanner-assets
```

This copies `jscanify.js` to `public/vendor/pipo-scanner/jscanify.js`.

**3. Optionally publish the config:**

```bash
php artisan vendor:publish --tag=pipo-scanner-config
```

---

## Register the Plugin

In your panel provider (e.g. `app/Providers/Filament/AdminPanelProvider.php`):

```php
use Lacv\PipoScanner\PipoScannerPlugin;

public function panel(Panel $panel): Panel
{
    return $panel
        ->plugin(PipoScannerPlugin::make());
}
```

---

## Usage

### Basic integration in a Filament Form

The scanner is a Blade component rendered inside a Filament `ViewField`. It communicates back to Livewire via two mechanisms:

1. Calls `$wire.setScannerDocumentPath(path)` on the Livewire component.
2. Dispatches a browser event `scanner-saved` with `{ path, url }`.

**Step 1 — Add a method to your Livewire page/resource:**

```php
public ?string $document_path = null;

public function setScannerDocumentPath(string $path): void
{
    $this->document_path = $path;
}
```

**Step 2 — Add the field to your form schema:**

```php
use Filament\Forms\Components\ViewField;
use Filament\Forms\Components\Hidden;

Hidden::make('document_path'),

ViewField::make('scanner')
    ->view('pipo-scanner::components.scanner')
    ->columnSpanFull(),
```

**Step 3 — Pass existing document in edit mode:**

The component reads `data-existing-path` and `data-existing-url` attributes injected by Livewire. In your `EditRecord` page, override `mutateFormDataBeforeFill()`:

```php
protected function mutateFormDataBeforeFill(array $data): array
{
    $this->js(
        "window.__scannerExistingPath = " . json_encode($data['document_path'] ?? null) . ";"
    );
    return $data;
}
```

Or pass them as `viewData` on the field:

```php
ViewField::make('scanner')
    ->view('pipo-scanner::components.scanner')
    ->viewData([
        'existingPath' => $this->record?->document_path,
        'existingUrl'  => $this->record?->document_path
            ? asset('storage/' . $this->record->document_path)
            : null,
    ])
    ->columnSpanFull(),
```

---

## How It Works

```
Browser Camera
    ↓
OpenCV.js (edge detection at ~4fps, downscaled 40% for performance)
    ↓
Auto-detect document corners → draw overlay on live feed
    ↓
User captures frame (or manually adjusts corners with loupe)
    ↓
jscanify crops & perspective-corrects the image
    ↓
User selects filter: color / grayscale / B&W
    ↓
User can rotate 90° or flip horizontally
    ↓
Repeat for multiple pages (accumulated in memory)
    ↓
jsPDF compiles all pages into a single PDF
    ↓
Base64 PDF sent via POST to /pipo-scanner/upload
    ↓
Laravel stores file on configured disk
    ↓
Returns { path, url } → updates Livewire component
```

---

## Events

### Browser event

After a successful save the component dispatches:

```js
$dispatch('scanner-saved', { path: 'documents/scanner/temp/scan_xxx.pdf', url: 'https://...' })
```

Listen to it with Alpine.js:

```html
<div x-on:scanner-saved.window="myHandler($event.detail)">
```

### Livewire method

The component automatically calls `$wire.setScannerDocumentPath(path)` on the parent Livewire component. Just define the method on your page/component:

```php
public function setScannerDocumentPath(string $path): void
{
    $this->data['document_path'] = $path;
}
```

---

## Configuration

After publishing the config file (`config/pipo-scanner.php`):

```php
return [
    // Storage disk (default: 'public')
    'disk' => env('PIPO_SCANNER_DISK', 'public'),

    // Directory within the disk
    'directory' => env('PIPO_SCANNER_DIRECTORY', 'documents/scanner/temp'),

    // Maximum file size in bytes (default: 4MB)
    'max_file_size' => env('PIPO_SCANNER_MAX_SIZE', 4 * 1024 * 1024),

    // Upload route URL path
    'upload_route' => env('PIPO_SCANNER_UPLOAD_ROUTE', '/pipo-scanner/upload'),
];
```

Or via `.env`:

```env
PIPO_SCANNER_DISK=s3
PIPO_SCANNER_DIRECTORY=scans/documents
PIPO_SCANNER_MAX_SIZE=8388608
```

---

## Mobile Considerations

- **Android**: Camera permission prompt appears on first use. The component defers `getUserMedia()` until an explicit user tap to ensure the gesture context required by Android browsers.
- **iOS (Safari)**: Supported on iOS 14.3+. Requires HTTPS.
- **HTTP on LAN**: `navigator.mediaDevices` is not exposed. Use HTTPS or `localhost`.

---

## Customizing the View

To override the default view, publish it:

```bash
php artisan vendor:publish --tag=pipo-scanner-views
```

The view will be copied to `resources/views/vendor/pipo-scanner/components/scanner.blade.php`.

---

## Credits

- [OpenCV.js](https://opencv.org/) — computer vision library for edge detection
- [jscanify](https://github.com/ColonelParrot/jscanify) — document scanning library built on OpenCV.js
- [jsPDF](https://github.com/parallax/jsPDF) — client-side PDF generation

---

## License

MIT — see [LICENSE](LICENSE) file.
