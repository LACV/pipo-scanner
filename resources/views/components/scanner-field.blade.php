@php
    $scannerExistingPath = $field->getExistingPath() ?? '';
    $scannerExistingUrl  = $field->getExistingUrl()  ?? '';
    $scannerStatePath    = $field->getStatePath();
    $scannerDirectory    = $field->getDirectory();
    $scannerMaxFileSize  = $field->getMaxFileSize();
    $scannerHeight       = $field->getHeight();
@endphp

@include('pipo-scanner::components.scanner')
