<?php

use App\Models\TvScreen;
use App\Services\TvBoard;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts::tv')] class extends Component
{
    public string $token = '';

    public function mount(string $token): void
    {
        $screen = TvScreen::query()->where('token', $token)->where('is_active', true)->firstOrFail();

        $this->token = $screen->token;

        if ($screen->last_seen_at === null || $screen->last_seen_at->lt(now()->subMinute())) {
            $screen->forceFill(['last_seen_at' => now()])->saveQuietly();
        }
    }

    public function rendering($view): void
    {
        app()->setLocale('ru');
        $view->title($this->screen()->name);
    }

    public function screen(): TvScreen
    {
        return TvScreen::query()->with('company')->where('token', $this->token)->where('is_active', true)->firstOrFail();
    }

    /**
     * @return array<string, mixed>
     */
    #[Computed]
    public function board(): array
    {
        return app(TvBoard::class)->build($this->screen()->company_id, now());
    }
};
?>

<div wire:poll.20s class="flex min-h-screen flex-col gap-6 p-6 xl:p-10"
     x-data="{ now: new Date() }" x-init="setInterval(() => now = new Date(), 1000)">
    @php($board = $this->board)
    @php($screen = $this->screen())

    <header class="flex items-center justify-between gap-6">
        <div class="flex items-center gap-4">
            <img src="{{ asset('images/brand/logo.jpg') }}" alt="" class="size-14 rounded-full">
            <div>
                <h1 class="text-3xl font-bold tracking-wide xl:text-4xl">{{ $screen->company?->name ?? 'THE NON' }}</h1>
                <p class="text-lg text-navy-100/70">{{ __('tv.subtitle') }}</p>
            </div>
        </div>

        <div class="flex items-center gap-8">
            <div class="text-right">
                <p class="text-sm uppercase tracking-wider text-navy-100/60">{{ __('tv.received') }}</p>
                <p class="text-4xl font-bold xl:text-5xl">
                    <span class="text-gold-400">{{ $board['ordered'] }}</span><span class="text-navy-100/50"> / {{ $board['expected'] }}</span>
                </p>
            </div>
            <div class="text-right">
                <p class="font-mono text-5xl font-bold tabular-nums xl:text-6xl" x-text="now.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' })"></p>
                <p class="text-lg text-navy-100/70" x-text="now.toLocaleDateString('ru-RU', { weekday: 'long', day: 'numeric', month: 'long' })"></p>
            </div>
        </div>
    </header>

    <div class="grid flex-1 gap-6 lg:grid-cols-12">
        {{-- Not ordered yet, most urgent first --}}
        <section class="space-y-3 lg:col-span-4">
            <h2 class="text-xl font-semibold uppercase tracking-wide text-red-300">{{ __('tv.missing') }} <span class="text-white/60">· {{ $board['missing']->count() }}</span></h2>

            @forelse ($board['missing'] as $row)
                @php($late = $row['minutes_left'] !== null && $row['minutes_left'] < 0)
                @php($soon = $row['minutes_left'] !== null && $row['minutes_left'] >= 0 && $row['minutes_left'] <= 30)
                <div wire:key="missing-{{ $row['location']->id }}" @class([
                    'rounded-2xl border-l-8 px-5 py-4',
                    'border-red-500 bg-red-500/20' => $late || $soon,
                    'border-amber-400 bg-white/10' => ! $late && ! $soon,
                ])>
                    <div class="flex items-start justify-between gap-4">
                        <div class="min-w-0">
                            @if (! $screen->company_id)
                                <p class="truncate text-sm text-navy-100/70">{{ $row['location']->company->name }}</p>
                            @endif
                            <p class="truncate text-2xl font-semibold">{{ $row['location']->name }}</p>
                            @if ($row['has_draft'])
                                <p class="text-sm text-amber-300">{{ __('tv.has_draft') }}</p>
                            @endif
                        </div>
                        <div class="shrink-0 text-right">
                            @if ($row['deadline'])
                                <p @class(['text-2xl font-bold tabular-nums', 'text-red-300' => $late || $soon, 'text-amber-300' => ! $late && ! $soon])>
                                    @if ($late)
                                        {{ __('tv.late') }}
                                    @else
                                        {{ intdiv($row['minutes_left'], 60) }}:{{ str_pad((string) ($row['minutes_left'] % 60), 2, '0', STR_PAD_LEFT) }}
                                    @endif
                                </p>
                                <p class="text-sm text-navy-100/70">{{ __('tv.until') }} {{ $row['deadline']->format('H:i') }}</p>
                            @else
                                <p class="text-sm text-navy-100/70">{{ __('tv.no_deadline') }}</p>
                            @endif
                        </div>
                    </div>
                </div>
            @empty
                <p class="rounded-2xl bg-emerald-500/20 px-5 py-8 text-center text-2xl font-semibold text-emerald-200">{{ __('tv.all_ordered') }}</p>
            @endforelse
        </section>

        {{-- Orders received, newest first --}}
        <section class="space-y-3 lg:col-span-5">
            <h2 class="text-xl font-semibold uppercase tracking-wide text-gold-400">{{ __('tv.orders') }} <span class="text-white/60">· {{ $board['orders']->count() }}</span></h2>

            @forelse ($board['orders']->take(10) as $row)
                @php($order = $row['order'])
                <div wire:key="order-{{ $order->id }}" @class([
                    'flex items-center justify-between gap-4 rounded-2xl px-5 py-4',
                    'animate-pulse bg-gold-400/30 ring-2 ring-gold-400' => $row['is_new'],
                    'bg-white/10' => ! $row['is_new'],
                ])>
                    <div class="min-w-0">
                        @if (! $screen->company_id)
                            <p class="truncate text-sm text-navy-100/70">{{ $order->location->company->name }}</p>
                        @endif
                        <p class="truncate text-2xl font-semibold">{{ $order->location->name }}</p>
                        <p class="text-sm text-navy-100/70">
                            {{ ($order->completed_at ?? $order->updated_at)->format('H:i') }} · {{ __('tv.positions', ['count' => $row['positions']]) }} · {{ $row['quantity'] }}
                        </p>
                    </div>
                    <div class="flex shrink-0 flex-col items-end gap-1">
                        @if ($row['is_new'])
                            <span class="rounded-full bg-gold-400 px-3 py-0.5 text-sm font-bold uppercase text-navy-950">{{ __('tv.new') }}</span>
                        @endif
                        <span class="rounded-full bg-white/15 px-3 py-1 text-base font-medium">{{ $order->status->label() }}</span>
                    </div>
                </div>
            @empty
                <p class="rounded-2xl bg-white/10 px-5 py-8 text-center text-xl text-navy-100/70">{{ __('tv.no_orders') }}</p>
            @endforelse

            @if ($board['orders']->count() > 10)
                <p class="text-center text-sm text-navy-100/60">{{ __('tv.more', ['count' => $board['orders']->count() - 10]) }}</p>
            @endif
        </section>

        {{-- Quantities to prepare --}}
        <section class="space-y-3 lg:col-span-3">
            <h2 class="text-xl font-semibold uppercase tracking-wide text-emerald-300">{{ __('tv.totals') }}</h2>

            <div class="divide-y divide-white/10 rounded-2xl bg-white/10">
                @forelse ($board['totals']->take(14) as $total)
                    <div wire:key="total-{{ $loop->index }}" class="flex items-baseline justify-between gap-4 px-5 py-3">
                        <p class="min-w-0 truncate text-xl">{{ $total['name'] }}</p>
                        <p class="shrink-0 text-2xl font-bold tabular-nums">{{ $total['quantity'] }} <span class="text-base font-normal text-navy-100/70">{{ $total['unit'] }}</span></p>
                    </div>
                @empty
                    <p class="px-5 py-8 text-center text-xl text-navy-100/70">—</p>
                @endforelse
            </div>
        </section>
    </div>
</div>
