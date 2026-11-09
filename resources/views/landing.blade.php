<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" class="scroll-pt-20">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('landing.meta.title') }}</title>
    <meta name="description" content="{{ __('landing.meta.description') }}">
    <meta property="og:title" content="{{ __('landing.meta.title') }}">
    <meta property="og:description" content="{{ __('landing.meta.description') }}">
    <meta property="og:image" content="{{ asset('images/brand/photo3.jpg') }}">
    <link rel="icon" href="{{ asset('images/brand/logo.jpg') }}">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
@php
    $phoneDisplay = '+998 95 144 94 00';
    $phoneLink = 'tel:+998951449400';
    $botUrl = 'https://t.me/TheNonuzBot';
    $instagramUrl = 'https://www.instagram.com/thenonuz/';
    $locales = ['uz' => 'UZ', 'ru' => 'RU'];
@endphp
<body class="bg-cream-50 font-sans text-navy-900 antialiased">

    {{-- Header --}}
    <header class="sticky top-0 z-40 border-b border-navy-100/70 bg-cream-50/90 backdrop-blur" x-data="{ open: false }">
        <div class="mx-auto flex h-16 max-w-6xl items-center justify-between gap-4 px-4 sm:px-6">
            <a href="{{ route('landing') }}" class="flex items-center gap-3">
                <img src="{{ asset('images/brand/logo.jpg') }}" alt="THE NON" class="size-10 rounded-full">
                <span class="text-lg font-semibold tracking-wide text-navy-800">THE NON</span>
            </a>

            <nav class="hidden items-center gap-7 text-sm font-medium text-navy-700 md:flex">
                <a href="#about" class="hover:text-navy-950">{{ __('landing.nav.about') }}</a>
                <a href="#order" class="hover:text-navy-950">{{ __('landing.nav.order') }}</a>
                <a href="#bake" class="hover:text-navy-950">{{ __('landing.nav.bake') }}</a>
                <a href="#business" class="hover:text-navy-950">{{ __('landing.nav.business') }}</a>
                <a href="#contact" class="hover:text-navy-950">{{ __('landing.nav.contact') }}</a>
            </nav>

            <div class="flex items-center gap-3">
                <div class="flex rounded-full bg-navy-50 p-0.5 text-xs font-semibold">
                    @foreach ($locales as $code => $label)
                        <a href="{{ route('locale.switch', $code) }}"
                           @class([
                               'rounded-full px-2.5 py-1 transition',
                               'bg-navy-800 text-white' => app()->getLocale() === $code,
                               'text-navy-700 hover:text-navy-950' => app()->getLocale() !== $code,
                           ])>{{ $label }}</a>
                    @endforeach
                </div>
                <a href="{{ $botUrl }}" target="_blank" rel="noopener"
                   class="hidden rounded-full bg-gold-400 px-4 py-2 text-sm font-semibold text-navy-950 transition hover:bg-gold-300 sm:inline-block">
                    {{ __('landing.nav.cta') }}
                </a>
                <button type="button" class="rounded-lg p-2 text-navy-800 md:hidden" @click="open = !open" aria-label="Menu" :aria-expanded="open">
                    <svg class="size-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" d="M4 7h16M4 12h16M4 17h16"/></svg>
                </button>
            </div>
        </div>

        <nav x-cloak x-show="open" x-transition class="border-t border-navy-100 bg-cream-50 px-4 py-3 md:hidden">
            <div class="flex flex-col gap-1 text-sm font-medium text-navy-800">
                @foreach (['about', 'order', 'bake', 'business', 'contact'] as $key)
                    <a href="#{{ $key }}" @click="open = false" class="rounded-lg px-3 py-2 hover:bg-navy-50">{{ __('landing.nav.'.$key) }}</a>
                @endforeach
                <a href="{{ $botUrl }}" target="_blank" rel="noopener" class="mt-2 rounded-full bg-gold-400 px-4 py-2.5 text-center font-semibold text-navy-950">{{ __('landing.nav.cta') }}</a>
            </div>
        </nav>
    </header>

    <main>
        {{-- Hero --}}
        <section class="relative isolate overflow-hidden bg-navy-900">
            <img src="{{ asset('images/brand/photo3.jpg') }}" alt="" class="absolute inset-0 -z-20 size-full object-cover opacity-50">
            <div class="absolute inset-0 -z-10 bg-linear-to-r from-navy-950 via-navy-900/85 to-navy-900/30"></div>
            <div class="mx-auto max-w-6xl px-4 py-24 sm:px-6 sm:py-32 lg:py-40">
                <p class="text-sm font-semibold uppercase tracking-[0.2em] text-gold-400">{{ __('landing.hero.eyebrow') }}</p>
                <h1 class="mt-5 max-w-2xl text-4xl font-semibold leading-tight text-white sm:text-6xl">{{ __('landing.hero.title') }}</h1>
                <p class="mt-6 max-w-xl text-lg text-navy-100">{{ __('landing.hero.text') }}</p>
                <div class="mt-10 flex flex-col gap-3 sm:flex-row">
                    <a href="{{ $botUrl }}" target="_blank" rel="noopener"
                       class="inline-flex items-center justify-center rounded-full bg-gold-400 px-7 py-3.5 font-semibold text-navy-950 transition hover:bg-gold-300">
                        {{ __('landing.hero.primary') }}
                    </a>
                    <a href="#business"
                       class="inline-flex items-center justify-center rounded-full border border-white/30 px-7 py-3.5 font-semibold text-white transition hover:bg-white/10">
                        {{ __('landing.hero.secondary') }}
                    </a>
                </div>
            </div>
        </section>

        {{-- About --}}
        <section id="about" class="mx-auto max-w-6xl px-4 py-20 sm:px-6 sm:py-28">
            <div class="grid items-center gap-12 lg:grid-cols-2">
                <div>
                    <p class="text-sm font-semibold uppercase tracking-[0.2em] text-gold-600">{{ __('landing.about.eyebrow') }}</p>
                    <h2 class="mt-3 text-3xl font-semibold text-navy-800 sm:text-4xl">{{ __('landing.about.title') }}</h2>
                    <p class="mt-5 text-lg leading-relaxed text-navy-700">{{ __('landing.about.text') }}</p>
                    <ul class="mt-8 space-y-5">
                        @foreach (__('landing.about.points') as $point)
                            <li class="flex gap-4">
                                <span class="mt-1 flex size-8 shrink-0 items-center justify-center rounded-full bg-gold-400/20 text-gold-600">
                                    <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="m5 13 4 4L19 7"/></svg>
                                </span>
                                <div>
                                    <p class="font-semibold text-navy-800">{{ $point['title'] }}</p>
                                    <p class="text-navy-700">{{ $point['text'] }}</p>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <img src="{{ asset('images/brand/photo1.jpg') }}" alt="THE NON baguettes" loading="lazy" class="aspect-[4/5] w-full rounded-3xl object-cover shadow-lg">
                    <img src="{{ asset('images/brand/photo2.jpg') }}" alt="THE NON croissants" loading="lazy" class="mt-10 aspect-[4/5] w-full rounded-3xl object-cover shadow-lg">
                </div>
            </div>
        </section>

        {{-- How to order --}}
        <section id="order" class="bg-cream-100">
            <div class="mx-auto max-w-6xl px-4 py-20 sm:px-6 sm:py-28">
                <p class="text-sm font-semibold uppercase tracking-[0.2em] text-gold-600">{{ __('landing.order.eyebrow') }}</p>
                <h2 class="mt-3 text-3xl font-semibold text-navy-800 sm:text-4xl">{{ __('landing.order.title') }}</h2>
                <ol class="mt-12 grid gap-6 md:grid-cols-3">
                    @foreach (__('landing.order.steps') as $i => $step)
                        <li class="rounded-3xl bg-white p-7 shadow-sm ring-1 ring-navy-100">
                            <span class="flex size-11 items-center justify-center rounded-full bg-navy-800 text-lg font-semibold text-gold-400">{{ $i + 1 }}</span>
                            <h3 class="mt-5 text-lg font-semibold text-navy-800">{{ $step['title'] }}</h3>
                            <p class="mt-2 text-navy-700">{{ $step['text'] }}</p>
                        </li>
                    @endforeach
                </ol>
                <a href="{{ $botUrl }}" target="_blank" rel="noopener"
                   class="mt-10 inline-flex rounded-full bg-navy-800 px-7 py-3.5 font-semibold text-white transition hover:bg-navy-700">
                    {{ __('landing.order.cta') }}
                </a>
            </div>
        </section>

        {{-- How to bake --}}
        <section id="bake" class="mx-auto max-w-6xl px-4 py-20 sm:px-6 sm:py-28">
            <p class="text-sm font-semibold uppercase tracking-[0.2em] text-gold-600">{{ __('landing.bake.eyebrow') }}</p>
            <h2 class="mt-3 text-3xl font-semibold text-navy-800 sm:text-4xl">{{ __('landing.bake.title') }}</h2>
            <ol class="mt-12 grid gap-6 md:grid-cols-3">
                @foreach (__('landing.bake.steps') as $i => $step)
                    <li class="relative rounded-3xl border border-cream-200 bg-cream-100/60 p-7">
                        <span class="text-5xl font-semibold text-crust-500/30">0{{ $i + 1 }}</span>
                        <h3 class="mt-2 text-lg font-semibold text-navy-800">{{ $step['title'] }}</h3>
                        <p class="mt-2 text-navy-700">{{ $step['text'] }}</p>
                    </li>
                @endforeach
            </ol>
        </section>

        {{-- Business --}}
        <section id="business" class="bg-navy-900 text-white">
            <div class="mx-auto grid max-w-6xl items-center gap-12 px-4 py-20 sm:px-6 sm:py-28 lg:grid-cols-2">
                <div>
                    <p class="text-sm font-semibold uppercase tracking-[0.2em] text-gold-400">{{ __('landing.business.eyebrow') }}</p>
                    <h2 class="mt-3 text-3xl font-semibold sm:text-4xl">{{ __('landing.business.title') }}</h2>
                    <p class="mt-5 text-lg leading-relaxed text-navy-100">{{ __('landing.business.text') }}</p>
                    <a href="{{ $botUrl }}" target="_blank" rel="noopener"
                       class="mt-8 inline-flex rounded-full bg-gold-400 px-7 py-3.5 font-semibold text-navy-950 transition hover:bg-gold-300">
                        {{ __('landing.business.cta') }}
                    </a>
                </div>
                <ul class="grid gap-4 sm:grid-cols-2">
                    @foreach (__('landing.business.points') as $point)
                        <li class="rounded-2xl bg-white/5 p-5 ring-1 ring-white/10">
                            <span class="mb-3 block h-1 w-8 rounded-full bg-gold-400"></span>
                            <p class="font-medium">{{ $point }}</p>
                        </li>
                    @endforeach
                </ul>
            </div>
        </section>

        {{-- Contact --}}
        <section id="contact" class="mx-auto max-w-6xl px-4 py-20 sm:px-6 sm:py-28">
            <p class="text-sm font-semibold uppercase tracking-[0.2em] text-gold-600">{{ __('landing.contact.eyebrow') }}</p>
            <h2 class="mt-3 text-3xl font-semibold text-navy-800 sm:text-4xl">{{ __('landing.contact.title') }}</h2>
            <div class="mt-10 grid gap-5 md:grid-cols-3">
                <a href="{{ $phoneLink }}" class="rounded-3xl bg-white p-7 shadow-sm ring-1 ring-navy-100 transition hover:ring-gold-400">
                    <p class="text-sm text-navy-600">{{ __('landing.contact.phone') }}</p>
                    <p class="mt-2 text-xl font-semibold text-navy-800">{{ $phoneDisplay }}</p>
                </a>
                <a href="{{ $botUrl }}" target="_blank" rel="noopener" class="rounded-3xl bg-white p-7 shadow-sm ring-1 ring-navy-100 transition hover:ring-gold-400">
                    <p class="text-sm text-navy-600">{{ __('landing.contact.telegram') }}</p>
                    <p class="mt-2 text-xl font-semibold text-navy-800">@TheNonuzBot</p>
                </a>
                <a href="{{ $instagramUrl }}" target="_blank" rel="noopener" class="rounded-3xl bg-white p-7 shadow-sm ring-1 ring-navy-100 transition hover:ring-gold-400">
                    <p class="text-sm text-navy-600">{{ __('landing.contact.instagram') }}</p>
                    <p class="mt-2 text-xl font-semibold text-navy-800">@thenonuz</p>
                </a>
            </div>
        </section>
    </main>

    <footer class="border-t border-navy-100 bg-cream-100/60">
        <div class="mx-auto flex max-w-6xl flex-col items-center justify-between gap-3 px-4 py-8 text-sm text-navy-600 sm:flex-row sm:px-6">
            <p>&copy; {{ now()->year }} THE NON. {{ __('landing.footer.rights') }}</p>
            <a href="{{ route('crm.login') }}" class="font-medium text-navy-700 hover:text-navy-950">{{ __('landing.footer.crm') }}</a>
        </div>
    </footer>
    @livewireScripts
</body>
</html>
