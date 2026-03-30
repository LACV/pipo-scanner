@php
    // Resolve existing document and state path from the ScannerField component instance.
    $scannerExistingPath = $field->getExistingPath() ?? '';
    $scannerExistingUrl  = $field->getExistingUrl()  ?? '';
    $scannerStatePath    = $field->getStatePath();
@endphp

@include('pipo-scanner::components.scanner')
