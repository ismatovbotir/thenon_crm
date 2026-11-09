<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Telegram Web App mock — THE NON</title>
    @vite(['resources/css/app.css'])
</head>
<body class="min-h-screen bg-navy-50 font-sans text-navy-900 antialiased">
    <div class="mx-auto flex max-w-6xl flex-col gap-8 px-4 py-8 lg:flex-row">
        <aside class="w-full space-y-4 lg:w-96">
            <div>
                <p class="text-xs font-semibold uppercase tracking-[0.2em] text-gold-600">Development only</p>
                <h1 class="mt-1 text-2xl font-semibold text-navy-800">Telegram Web App mock</h1>
                <p class="mt-2 text-sm text-navy-600">
                    Opens <code class="rounded bg-white px-1">/app</code> as if Telegram had launched it, with correctly signed login data.
                    Pick an employee to sign in as. Employees who are not registered in the bot, or are deactivated, show the "access denied" state.
                </p>
            </div>

            @if ($selected)
                <form method="POST" action="{{ route('dev.telegram.stop') }}">
                    @csrf
                    <button class="crm-btn-secondary w-full">Sign out of the mock</button>
                </form>
            @endif

            @foreach ($companies as $company)
                <section class="crm-card overflow-hidden">
                    <h2 class="border-b border-navy-100 bg-navy-50/60 px-4 py-2.5 text-sm font-semibold text-navy-800">{{ $company->name }}</h2>
                    <ul class="divide-y divide-navy-100">
                        @forelse ($company->employees as $employee)
                            <li class="flex items-center justify-between gap-3 px-4 py-2.5">
                                <div class="min-w-0">
                                    <p class="truncate text-sm font-medium text-navy-800">{{ $employee->name }}</p>
                                    <p class="truncate text-xs text-navy-600">
                                        {{ $employee->locations->pluck('name')->join(', ') ?: 'no locations' }}
                                        ·
                                        @if (! $employee->is_active)
                                            <span class="text-red-600">deactivated</span>
                                        @elseif ($employee->telegram_id)
                                            <span class="text-emerald-700">registered</span>
                                        @else
                                            <span class="text-gold-600">not registered</span>
                                        @endif
                                    </p>
                                </div>
                                <form method="POST" action="{{ route('dev.telegram.select', $employee) }}">
                                    @csrf
                                    <button @class(['crm-btn px-3 py-1.5 text-xs', 'bg-gold-400 text-navy-950' => $selected?->is($employee), 'border border-navy-100 bg-white text-navy-800 hover:bg-navy-50' => ! $selected?->is($employee)])>
                                        {{ $selected?->is($employee) ? 'Open again' : 'Open as' }}
                                    </button>
                                </form>
                            </li>
                        @empty
                            <li class="px-4 py-3 text-sm text-navy-600">No employees.</li>
                        @endforelse
                    </ul>
                </section>
            @endforeach
        </aside>

        <main class="flex flex-1 justify-center">
            {{-- Phone with a Telegram-like header --}}
            <div class="w-[390px] max-w-full shrink-0 overflow-hidden rounded-[2.5rem] bg-neutral-900 p-3 shadow-2xl ring-1 ring-black/20">
                <div class="overflow-hidden rounded-[2rem] bg-white">
                    <div class="flex items-center justify-between bg-[#517da2] px-4 py-2.5 text-white">
                        <span class="text-sm">Close</span>
                        <span class="text-center text-sm font-semibold leading-tight">TheNonuzBot<br><span class="text-[10px] font-normal opacity-80">mini app</span></span>
                        <span class="text-lg leading-none">&#8942;</span>
                    </div>
                    @if ($selected)
                        <iframe src="{{ route('app.home') }}" title="THE NON web app" class="block h-[760px] w-full border-0"></iframe>
                    @else
                        <div class="flex h-[760px] items-center justify-center bg-cream-50 px-8 text-center text-sm text-navy-600">
                            Choose an employee on the left to launch the web app.
                        </div>
                    @endif
                </div>
            </div>
        </main>
    </div>
</body>
</html>
