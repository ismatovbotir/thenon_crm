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
@php
    $user = auth()->user();
    $navigation = [
        ['key' => 'dashboard', 'route' => 'crm.dashboard', 'icon' => 'M3 12l9-9 9 9M5 10v10h5v-6h4v6h5V10'],
        ['key' => 'orders', 'route' => 'crm.orders.index', 'match' => 'crm.orders.*', 'icon' => 'M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2M9 5a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2M9 5h6M9 12h6M9 16h4'],
        ['key' => 'companies', 'route' => 'crm.companies.index', 'match' => 'crm.companies.*', 'icon' => 'M3 21h18M5 21V7l7-4 7 4v14M9 9h1m4 0h1M9 13h1m4 0h1M9 17h1m4 0h1'],
        ['key' => 'items', 'route' => 'crm.items.index', 'match' => 'crm.items.*', 'icon' => 'M21 8l-9-5-9 5 9 5 9-5zM3 8v8l9 5 9-5V8M12 13v8'],
        ['key' => 'reports', 'icon' => 'M4 20V10m6 10V4m6 16v-7m4 7H2', 'match' => ['crm.reports', 'crm.reports.*'], 'children' => [
            ['key' => 'report_production', 'route' => 'crm.reports', 'match' => 'crm.reports'],
            ['key' => 'report_sales', 'route' => 'crm.reports.sales'],
            ['key' => 'report_daily', 'route' => 'crm.reports.daily'],
            ['key' => 'report_compare', 'route' => 'crm.reports.compare'],
            ['key' => 'report_missing', 'route' => 'crm.reports.missing'],
        ]],
        ['key' => 'settings', 'route' => 'crm.settings', 'admin' => true, 'icon' => 'M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6zM19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z'],
        ['key' => 'tv', 'route' => 'crm.tv', 'admin' => true, 'icon' => 'M3 5h18v11H3zM8 20h8M12 16v4'],
        ['key' => 'system', 'route' => 'crm.system', 'admin' => true, 'icon' => 'M5 12h14M5 12a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v4a2 2 0 0 1-2 2M5 12a2 2 0 0 0-2 2v4a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-4a2 2 0 0 0-2-2M7 8h.01M7 16h.01'],
    ];
    $visible = fn ($entry) => ! ($entry['admin'] ?? false) || $user?->isAdmin();
    $navigation = array_values(array_filter(array_map(function ($entry) use ($visible) {
        if (isset($entry['children'])) {
            $entry['children'] = array_values(array_filter($entry['children'], $visible));
        }

        return $entry;
    }, array_filter($navigation, $visible)), fn ($entry) => ! isset($entry['children']) || $entry['children'] !== []));
@endphp
<body class="bg-navy-50 font-sans text-navy-900 antialiased" x-data="{ sidebar: false }">

    {{-- Mobile backdrop --}}
    <div x-cloak x-show="sidebar" x-transition.opacity class="fixed inset-0 z-40 bg-navy-950/60 lg:hidden" @click="sidebar = false"></div>

    {{-- Sidebar --}}
    <aside class="fixed inset-y-0 left-0 z-50 flex w-64 -translate-x-full flex-col bg-navy-900 text-navy-100 transition-transform lg:translate-x-0"
           :class="{ 'translate-x-0': sidebar }">
        <a href="{{ route('crm.dashboard') }}" class="flex h-16 items-center gap-3 border-b border-white/10 px-5">
            <img src="{{ asset('images/brand/logo.jpg') }}" alt="" class="size-9 rounded-full">
            <span class="font-semibold tracking-wide text-white">THE NON <span class="text-gold-400">CRM</span></span>
        </a>

        <nav class="flex-1 space-y-1 overflow-y-auto px-3 py-4">
            @foreach ($navigation as $item)
                @if (isset($item['children']))
                    @php($groupActive = request()->routeIs(...$item['match']))
                    <div x-data="{ open: {{ $groupActive ? 'true' : 'false' }} }" class="space-y-1">
                        <button type="button" @click="open = ! open" @class([
                            'flex w-full items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium transition hover:bg-white/10 hover:text-white',
                            'text-white' => $groupActive,
                        ])>
                            <svg class="size-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="{{ $item['icon'] }}"/></svg>
                            <span class="flex-1 text-left">{{ __('crm.nav.'.$item['key']) }}</span>
                            <svg class="size-4 shrink-0 transition" :class="{ 'rotate-180': open }" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 9l6 6 6-6"/></svg>
                        </button>
                        <div x-show="open" x-cloak class="ml-5 space-y-1 border-l border-white/10 pl-3">
                            @foreach ($item['children'] as $child)
                                <a href="{{ route($child['route']) }}" wire:navigate
                                   @class([
                                       'block rounded-lg px-3 py-2 text-sm font-medium transition',
                                       'bg-gold-400 text-navy-950' => request()->routeIs($child['match'] ?? $child['route']),
                                       'hover:bg-white/10 hover:text-white' => ! request()->routeIs($child['match'] ?? $child['route']),
                                   ])>{{ __('crm.nav.'.$child['key']) }}</a>
                            @endforeach
                        </div>
                    </div>
                @elseif (isset($item['route']))
                    <a href="{{ route($item['route']) }}" wire:navigate
                       @class([
                           'group flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium transition',
                           'bg-gold-400 text-navy-950' => request()->routeIs($item['match'] ?? $item['route']),
                           'hover:bg-white/10 hover:text-white' => ! request()->routeIs($item['match'] ?? $item['route']),
                       ])>
                        <svg class="size-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="{{ $item['icon'] }}"/></svg>
                        {{ __('crm.nav.'.$item['key']) }}
                    </a>
                @else
                    <div class="flex cursor-not-allowed items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium text-navy-100/45" aria-disabled="true">
                        <svg class="size-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="{{ $item['icon'] }}"/></svg>
                        <span class="flex-1">{{ __('crm.nav.'.$item['key']) }}</span>
                        <span class="rounded-full bg-white/10 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide">{{ __('crm.nav.soon') }}</span>
                    </div>
                @endif
            @endforeach
        </nav>

        <div class="border-t border-white/10 p-4">
            <div class="flex items-center gap-3">
                <span class="flex size-9 items-center justify-center rounded-full bg-gold-400 text-sm font-semibold text-navy-950">{{ mb_strtoupper(mb_substr($user->name, 0, 1)) }}</span>
                <div class="min-w-0">
                    <p class="truncate text-sm font-medium text-white">{{ $user->name }}</p>
                    <p class="truncate text-xs text-navy-100/70">{{ $user->role->label() }}</p>
                </div>
            </div>
        </div>
    </aside>

    <div class="lg:pl-64">
        {{-- Top bar --}}
        <header class="sticky top-0 z-30 flex h-16 items-center justify-between gap-4 border-b border-navy-100 bg-white/90 px-4 backdrop-blur sm:px-6">
            <button type="button" class="rounded-lg p-2 text-navy-800 hover:bg-navy-50 lg:hidden" @click="sidebar = true" aria-label="{{ __('crm.nav.menu') }}">
                <svg class="size-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" d="M4 7h16M4 12h16M4 17h16"/></svg>
            </button>
            <h1 class="hidden text-lg font-semibold text-navy-800 lg:block">{{ $title ?? '' }}</h1>

            <div class="ml-auto flex items-center gap-3">
                <div class="flex rounded-full bg-navy-50 p-0.5 text-xs font-semibold">
                    @foreach (['uz' => 'UZ', 'ru' => 'RU'] as $code => $label)
                        <a href="{{ route('locale.switch', $code) }}"
                           @class([
                               'rounded-full px-2.5 py-1 transition',
                               'bg-navy-800 text-white' => app()->getLocale() === $code,
                               'text-navy-700 hover:text-navy-950' => app()->getLocale() !== $code,
                           ])>{{ $label }}</a>
                    @endforeach
                </div>
                <form method="POST" action="{{ route('crm.logout') }}">
                    @csrf
                    <button type="submit" class="rounded-lg px-3 py-2 text-sm font-medium text-navy-700 hover:bg-navy-50 hover:text-navy-950">
                        {{ __('crm.nav.logout') }}
                    </button>
                </form>
            </div>
        </header>

        <main class="p-4 sm:p-6 lg:p-8">
            {{ $slot }}
        </main>
    </div>

    {{-- Toasts: $this->dispatch('notify', message: '…', type: 'success'|'error') from any component --}}
    <div x-data="{
             toasts: [],
             add(detail) {
                 const toast = { id: Date.now() + Math.random(), message: detail.message ?? '', type: detail.type ?? 'success' }
                 this.toasts.push(toast)
                 setTimeout(() => this.toasts = this.toasts.filter((item) => item.id !== toast.id), 4500)
             },
         }"
         x-on:notify.window="add($event.detail)"
         class="pointer-events-none fixed bottom-4 right-4 z-[80] flex w-full max-w-sm flex-col gap-2 px-4 sm:px-0" aria-live="polite">
        <template x-for="toast in toasts" :key="toast.id">
            <div x-transition class="pointer-events-auto flex items-start gap-3 rounded-xl bg-navy-900 px-4 py-3 text-sm text-white shadow-lg ring-1 ring-black/10">
                <span class="mt-0.5 size-2 shrink-0 rounded-full" :class="toast.type === 'error' ? 'bg-red-400' : 'bg-emerald-400'"></span>
                <p class="min-w-0 flex-1" x-text="toast.message"></p>
                <button type="button" class="text-white/60 hover:text-white" x-on:click="toasts = toasts.filter((item) => item.id !== toast.id)" aria-label="{{ __('crm.common.close') }}">×</button>
            </div>
        </template>
    </div>
</body>
</html>
