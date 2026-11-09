<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $title ?? 'CRM' }} — THE NON</title>
    <link rel="icon" href="{{ asset('images/brand/logo.jpg') }}">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-navy-950 font-sans text-navy-900 antialiased">
    <div class="relative isolate flex min-h-screen items-center justify-center overflow-hidden px-4 py-12">
        <img src="{{ asset('images/brand/photo3.jpg') }}" alt="" class="absolute inset-0 -z-20 size-full object-cover opacity-20">
        <div class="absolute inset-0 -z-10 bg-linear-to-br from-navy-950 via-navy-900/95 to-navy-800/80"></div>

        <div class="w-full max-w-md">
            <div class="mb-8 flex flex-col items-center text-center">
                <img src="{{ asset('images/brand/logo.jpg') }}" alt="THE NON" class="size-16 rounded-full ring-4 ring-gold-400/30">
                <p class="mt-4 text-sm font-semibold uppercase tracking-[0.25em] text-gold-400">THE NON</p>
            </div>

            {{ $slot }}

            <div class="mt-6 flex items-center justify-between text-sm text-navy-100/80">
                <a href="{{ route('landing') }}" class="hover:text-white">&larr; {{ __('crm.login.back') }}</a>
                <div class="flex gap-1 text-xs font-semibold">
                    @foreach (['uz' => 'UZ', 'ru' => 'RU'] as $code => $label)
                        <a href="{{ route('locale.switch', $code) }}"
                           @class([
                               'rounded-full px-2.5 py-1',
                               'bg-gold-400 text-navy-950' => app()->getLocale() === $code,
                               'text-navy-100 hover:text-white' => app()->getLocale() !== $code,
                           ])>{{ $label }}</a>
                    @endforeach
                </div>
            </div>
        </div>
    </div>
</body>
</html>
