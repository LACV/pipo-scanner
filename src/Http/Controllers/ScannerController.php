<?php

namespace Lacv\PipoScanner\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ScannerController extends Controller
{
    public function upload(Request $request): JsonResponse
    {
        $request->validate([
            'pdf'       => ['required', 'string'],
            'directory' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $dataUrl = $request->input('pdf');

        if (! preg_match('/^data:application\/pdf;[^,]+,/i', $dataUrl)) {
            return response()->json(['error' => 'Invalid PDF format.'], 422);
        }

        $base64   = preg_replace('/^data:application\/pdf;[^,]+,/i', '', $dataUrl);
        $fileData = base64_decode($base64, strict: true);

        if ($fileData === false) {
            return response()->json(['error' => 'Could not decode the file.'], 422);
        }

        $maxBytes = config('pipo-scanner.max_file_size', 4 * 1024 * 1024);
        if (strlen($fileData) > $maxBytes) {
            return response()->json(['error' => 'File exceeds maximum allowed size.'], 422);
        }

        $disk      = config('pipo-scanner.disk', 'public');
        $directory = $request->input('directory') ?: config('pipo-scanner.directory', 'documents/scanner/temp');
        $filename  = 'scan_' . now()->format('Ymd_His') . '_' . Str::random(8) . '.pdf';
        $path      = $directory . '/' . $filename;

        Storage::disk($disk)->put($path, $fileData);

        return response()->json([
            'path' => $path,
            'url'  => Storage::disk($disk)->url($path),
        ]);
    }
}
