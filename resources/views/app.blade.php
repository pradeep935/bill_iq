@php
    Illuminate\Support\Facades\URL::forceRootUrl(request()->getSchemeAndHttpHost());
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Bill IQ</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="icon" type="image/svg+xml" href="{{ url('/assets/images/billiq-favicon.svg') }}">
    @inertiaHead
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
    @inertia
</body>
</html>
