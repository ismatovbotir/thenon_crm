<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $title ?? 'THE NON' }}</title>
    <link rel="icon" href="{{ asset('images/brand/logo.jpg') }}">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen cursor-none bg-navy-950 font-sans text-white antialiased">
    {{ $slot }}
</body>
</html>
