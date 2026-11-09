<?php

use App\Models\TvScreen;
use App\Services\TvBoard;
use Illuminate\Support\Collection;
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
     * One slide per company (a company's own screen has just one).
     *
     * @return Collection<int, array{company: App\Models\Company, board: array<string, mixed>}>
     */
    #[Computed]
    public function slides(): Collection
    {
        return app(TvBoard::class)->slides($this->screen()->company_id, now());
    }
};
?>

@php($screen = $this->screen())
@php($slides = $this->slides)
@php($ordered = $slides->sum(fn ($slide) => $slide['board']['ordered']))
@php($expected = $slides->sum(fn ($slide) => $slide['board']['expected']))
@php($rotate = $slides->count() > 1)

<div wire:poll.20s class="flex h-screen flex-col overflow-hidden"
     x-data="{
         i: 0,
         started: Date.now(),
         progress: 0,
         now: new Date(),
         interval: {{ TvBoard::SLIDE_SECONDS }} * 1000,
         count() { return this.$root.querySelectorAll('[data-slide]').length },
         next() { this.i = (this.i + 1) % Math.max(1, this.count()); this.started = Date.now() },
         tick() {
             this.now = new Date();
             if (this.i >= this.count()) { this.i = 0 }
             this.progress = Math.min(100, (Date.now() - this.started) / this.interval * 100);
             if (this.count() > 1 && Date.now() - this.started >= this.interval) { this.next() }
         },
     }"
     x-init="setInterval(() => tick(), 200)">

    {{-- Header --}}
    <header class="flex shrink-0 items-center justify-between gap-6 px-6 pb-3 pt-5 xl:px-10">
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
                    <span class="text-gold-400">{{ $ordered }}</span><span class="text-navy-100/50"> / {{ $expected }}</span>
                </p>
            </div>
            <div class="text-right">
                <p class="font-mono text-5xl font-bold tabular-nums xl:text-6xl" x-text="now.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' })"></p>
                <p class="text-lg text-navy-100/70" x-text="now.toLocaleDateString('ru-RU', { weekday: 'long', day: 'numeric', month: 'long' })"></p>
            </div>
        </div>
    </header>

    {{-- Company tabs with the 15 second timer --}}
    @if ($rotate)
        <nav class="flex shrink-0 gap-2 overflow-hidden px-6 xl:px-10" aria-label="{{ __('tv.companies') }}">
            @foreach ($slides as $slide)
                @php($pending = $slide['board']['missing']->count())
                <button type="button" wire:key="tab-{{ $slide['company']->id }}" x-on:click="i = {{ $loop->index }}; started = Date.now()"
                        class="relative min-w-0 shrink overflow-hidden rounded-xl px-4 py-2 text-left transition"
                        :class="i === {{ $loop->index }} ? 'bg-white/20 text-white' : 'bg-white/5 text-navy-100/60'">
                    <span class="flex items-center gap-2">
                        <span class="truncate text-lg font-semibold">{{ $slide['company']->name }}</span>
                        @if ($pending > 0)
                            <span class="shrink-0 rounded-full bg-red-500 px-2 text-sm font-bold text-white">{{ $pending }}</span>
                        @else
                            <span class="shrink-0 text-emerald-300">✓</span>
                        @endif
                    </span>
                    <span x-show="i === {{ $loop->index }}" class="absolute inset-x-0 bottom-0 h-1 bg-gold-400" :style="`width: ${progress}%`"></span>
                </button>
            @endforeach
        </nav>
    @endif

    {{-- Slides --}}
    <div class="min-h-0 flex-1 overflow-hidden">
        <div class="flex h-full transition-transform duration-700 ease-in-out" :style="`transform: translateX(-${i * 100}%)`">
            @forelse ($slides as $slide)
                @php($board = $slide['board'])
                {{-- Lists of many locations flow into 2 or 3 columns (6 rows each); the widths follow the columns needed. --}}
                @php($columnsFor = fn (int $count) => $count <= 6 ? 1 : ($count <= 12 ? 2 : 3))
                @php($missCols = $columnsFor($board['missing']->count()))
                @php($orderCols = $columnsFor($board['orders']->count()))
                @php($totalsSpan = max($missCols, $orderCols) >= 3 ? 2 : 3)
                @php($space = 12 - $totalsSpan)
                @php($missSpan = min($space - 3, max(3, (int) floor($space * $missCols / ($missCols + $orderCols)))))
                @php($orderSpan = $space - $missSpan)
                @php($spans = [2 => 'lg:col-span-2', 3 => 'lg:col-span-3', 4 => 'lg:col-span-4', 5 => 'lg:col-span-5', 6 => 'lg:col-span-6', 7 => 'lg:col-span-7', 9 => 'lg:col-span-9'])
                @php($gridCols = [1 => 'grid-cols-1', 2 => 'grid-cols-2', 3 => 'grid-cols-3'])
                <div data-slide wire:key="slide-{{ $slide['company']->id }}" class="grid h-full w-full shrink-0 gap-6 overflow-hidden px-6 py-4 lg:grid-cols-12 xl:px-10">

                    {{-- Not ordered yet, most urgent first --}}
                    <section class="min-h-0 space-y-3 overflow-hidden {{ $spans[$missSpan] }}">
                        <h2 class="text-xl font-semibold uppercase tracking-wide text-red-300">{{ __('tv.missing') }} <span class="text-white/60">· {{ $board['missing']->count() }}</span></h2>

                        @if ($board['missing']->isEmpty())
                            <p class="rounded-2xl bg-emerald-500/20 px-5 py-8 text-center text-2xl font-semibold text-emerald-200">{{ __('tv.all_ordered') }}</p>
                        @else
                            <div class="grid gap-3 {{ $gridCols[$missCols] }}">
                                @foreach ($board['missing']->take($missCols * 6) as $row)
                                    @php($late = $row['minutes_left'] !== null && $row['minutes_left'] < 0)
                                    @php($soon = $row['minutes_left'] !== null && $row['minutes_left'] >= 0 && $row['minutes_left'] <= 30)
                                    <div @class([
                                        'min-w-0 rounded-2xl border-l-8',
                                        'px-5 py-3' => $missCols === 1,
                                        'px-3 py-2' => $missCols > 1,
                                        'border-red-500 bg-red-500/20' => $late || $soon,
                                        'border-amber-400 bg-white/10' => ! $late && ! $soon,
                                    ])>
                                        <div class="flex items-start justify-between gap-3">
                                            <div class="min-w-0">
                                                <p @class(['truncate font-semibold', 'text-2xl' => $missCols === 1, 'text-lg' => $missCols > 1])>{{ $row['location']->name }}</p>
                                                @if ($row['has_draft'])
                                                    <p class="truncate text-sm text-amber-300">{{ __('tv.has_draft') }}</p>
                                                @endif
                                            </div>
                                            <div class="shrink-0 text-right">
                                                @if ($row['deadline'])
                                                    <p @class(['font-bold tabular-nums', 'text-2xl' => $missCols === 1, 'text-lg' => $missCols > 1, 'text-red-300' => $late || $soon, 'text-amber-300' => ! $late && ! $soon])>
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
                                @endforeach
                            </div>
                        @endif

                        @if ($board['missing']->count() > $missCols * 6)
                            <p class="text-center text-sm text-navy-100/60">{{ __('tv.more', ['count' => $board['missing']->count() - $missCols * 6]) }}</p>
                        @endif
                    </section>

                    {{-- Orders received, newest first --}}
                    <section class="min-h-0 space-y-3 overflow-hidden {{ $spans[$orderSpan] }}">
                        <h2 class="text-xl font-semibold uppercase tracking-wide text-gold-400">{{ __('tv.orders') }} <span class="text-white/60">· {{ $board['orders']->count() }}</span></h2>

                        @if ($board['orders']->isEmpty())
                            <p class="rounded-2xl bg-white/10 px-5 py-8 text-center text-xl text-navy-100/70">{{ __('tv.no_orders') }}</p>
                        @else
                            <div class="grid gap-3 {{ $gridCols[$orderCols] }}">
                                @foreach ($board['orders']->take($orderCols * 6) as $row)
                                    @php($order = $row['order'])
                                    <div @class([
                                        'flex min-w-0 items-center justify-between gap-3 rounded-2xl',
                                        'px-5 py-3' => $orderCols === 1,
                                        'px-3 py-2' => $orderCols > 1,
                                        'animate-pulse bg-gold-400/30 ring-2 ring-gold-400' => $row['is_new'],
                                        'bg-white/10' => ! $row['is_new'],
                                    ])>
                                        <div class="min-w-0">
                                            <p @class(['truncate font-semibold', 'text-2xl' => $orderCols === 1, 'text-lg' => $orderCols > 1])>{{ $order->location->name }}</p>
                                            <p class="truncate text-sm text-navy-100/70">
                                                {{ ($order->completed_at ?? $order->updated_at)->format('H:i') }} · {{ __('tv.positions', ['count' => $row['positions']]) }} · {{ $row['quantity'] }}
                                            </p>
                                        </div>
                                        <div class="flex shrink-0 flex-col items-end gap-1">
                                            @if ($row['is_new'])
                                                <span class="rounded-full bg-gold-400 px-2.5 py-0.5 text-xs font-bold uppercase text-navy-950">{{ __('tv.new') }}</span>
                                            @endif
                                            <span @class(['rounded-full bg-white/15 font-medium', 'px-3 py-1 text-base' => $orderCols === 1, 'px-2 py-0.5 text-sm' => $orderCols > 1])>{{ $order->status->label() }}</span>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        @endif

                        @if ($board['orders']->count() > $orderCols * 6)
                            <p class="text-center text-sm text-navy-100/60">{{ __('tv.more', ['count' => $board['orders']->count() - $orderCols * 6]) }}</p>
                        @endif
                    </section>

                    {{-- Quantities to prepare --}}
                    <section class="min-h-0 space-y-3 overflow-hidden {{ $spans[$totalsSpan] }}">
                        <h2 class="text-xl font-semibold uppercase tracking-wide text-emerald-300">{{ __('tv.totals') }}</h2>

                        <div class="divide-y divide-white/10 rounded-2xl bg-white/10">
                            @forelse ($board['totals']->take(10) as $total)
                                <div class="flex items-baseline justify-between gap-3 px-4 py-2.5">
                                    <p class="min-w-0 truncate text-lg">{{ $total['name'] }}</p>
                                    <p class="shrink-0 text-xl font-bold tabular-nums">{{ $total['quantity'] }} <span class="text-sm font-normal text-navy-100/70">{{ $total['unit'] }}</span></p>
                                </div>
                            @empty
                                <p class="px-5 py-8 text-center text-xl text-navy-100/70">—</p>
                            @endforelse
                        </div>
                    </section>
                </div>
            @empty
                <p class="flex h-full w-full items-center justify-center text-3xl text-navy-100/70">{{ __('tv.nothing') }}</p>
            @endforelse
        </div>
    </div>
</div>
