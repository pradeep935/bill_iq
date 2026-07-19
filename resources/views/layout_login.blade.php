<?php $version = env('JS_VERSION'); ?>
<!DOCTYPE html>
<html>
<head>
    <title>Bill IQ Login</title>
    <meta charset="utf-8">
    <meta name=viewport content="initial-scale=1">
    <link rel="icon" type="image/svg+xml" href="{{url('/assets/images/billiq-favicon.svg')}}">
    <link rel="stylesheet" type="text/css" href="{{url('assets/plugins/bootstrap/css/bootstrap.min.css')}}" />
    <link rel="stylesheet" type="text/css" href="{{url('assets/css/fonts.css?v='.$version)}}">
    <link rel="stylesheet" type="text/css" href="{{url('/assets/css/login.css?v='.$version)}}">
</head>

<body>
    @yield('content')
    <script type="text/javascript">
        var base_url = "{{url('/')}}";
    </script>
    @yield('footer_scripts')
</body>

</html>
