<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ __('telegram.app.title') }}</title>
    @vite(['resources/css/app.css'])
    <script src="https://telegram.org/js/telegram-web-app.js"></script>
</head>
<body class="flex min-h-screen items-center justify-center bg-navy-950 px-6 text-center font-sans text-white antialiased">
    <div class="max-w-xs space-y-4">
        @if (! empty($mockInitData))
            <p class="rounded-full bg-gold-400/20 px-3 py-1 text-xs font-semibold uppercase tracking-wide text-gold-300">Telegram mock</p>
        @endif
        <img src="{{ asset('images/brand/logo.jpg') }}" alt="THE NON" class="mx-auto size-16 rounded-full ring-4 ring-gold-400/30">
        <p id="status" class="text-navy-100">{{ __('telegram.app.loading') }}</p>
        <button id="retry" type="button" hidden onclick="location.reload()" class="crm-btn-gold">{{ __('telegram.app.retry') }}</button>
    </div>

    <script>
        (async () => {
            const status = document.getElementById('status');
            const retry = document.getElementById('retry');
            const fail = (message) => { status.textContent = message; retry.hidden = false; };
            const tg = window.Telegram && window.Telegram.WebApp;

            const mockInitData = @json($mockInitData ?? null);
            const initData = (tg && tg.initData) || mockInitData;

            if (!initData) {
                return fail(@json(__('telegram.app.open_in_telegram')));
            }

            if (tg) { tg.ready(); }

            try {
                const response = await fetch(@json(route('app.auth')), {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                    },
                    body: JSON.stringify({ init_data: initData }),
                });

                if (response.ok) {
                    return location.reload();
                }

                fail(response.status === 403 ? @json(__('telegram.app.denied')) : @json(__('telegram.app.retry')));
            } catch (error) {
                fail(@json(__('telegram.app.retry')));
            }
        })();
    </script>
</body>
</html>
