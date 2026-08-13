<!DOCTYPE html>
<html lang="en">
<head>
    @php
        $manifest = public_path('build/manifest.json');
        $assetVersion = config('app.asset_version') ?: (is_file($manifest) ? md5_file($manifest) : time());
    @endphp
    <title>Bill IQ</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="asset-version" content="{{ $assetVersion }}">
    <link rel="icon" type="image/svg+xml" href="{{ url('/assets/images/billiq-favicon.svg') }}?v={{ $assetVersion }}">
    @inertiaHead
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
    @inertia
</body>
</html>
