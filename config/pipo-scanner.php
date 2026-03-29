<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Storage Disk
    |--------------------------------------------------------------------------
    | The disk where scanned documents will be stored.
    */
    'disk' => env('PIPO_SCANNER_DISK', 'public'),

    /*
    |--------------------------------------------------------------------------
    | Storage Directory
    |--------------------------------------------------------------------------
    | The directory within the disk where files will be saved.
    */
    'directory' => env('PIPO_SCANNER_DIRECTORY', 'documents/scanner/temp'),

    /*
    |--------------------------------------------------------------------------
    | Max File Size
    |--------------------------------------------------------------------------
    | Maximum allowed file size in bytes. Default: 4MB.
    */
    'max_file_size' => env('PIPO_SCANNER_MAX_SIZE', 4 * 1024 * 1024),

    /*
    |--------------------------------------------------------------------------
    | Upload Route
    |--------------------------------------------------------------------------
    | The URL path for the PDF upload endpoint.
    */
    'upload_route' => env('PIPO_SCANNER_UPLOAD_ROUTE', '/pipo-scanner/upload'),
];
