@props(['title', 'maxWidth' => 'max-w-lg', 'subtitle' => null])

{{-- Slide-over drawer (bottom sheet on phones). Closes on Esc or backdrop click, and warns before discarding typed changes. --}}
@php($width = match ($maxWidth) { 'max-w-sm' => 'sm:max-w-sm', 'max-w-md' => 'sm:max-w-md', 'max-w-xl' => 'sm:max-w-xl', 'max-w-2xl' => 'sm:max-w-2xl', default => 'sm:max-w-lg' })

<div class="fixed inset-0 z-[60]" role="dialog" aria-modal="true" aria-label="{{ $title }}"
     x-data="{
         shown: false,
         dirty: false,
         asking: false,
         close() {
             if (this.dirty) { this.asking = true; return }
             $wire.closeForm()
         },
     }"
     x-init="$nextTick(() => { shown = true; $nextTick(() => $el.querySelector('[data-autofocus], input:not([type=hidden]), select, textarea')?.focus()) })"
     x-on:keydown.escape.window="close()"
     x-on:input.capture="dirty = true"
     x-on:change.capture="dirty = true">
    <div class="absolute inset-0 bg-navy-950/50 transition-opacity duration-200" :class="shown ? 'opacity-100' : 'opacity-0'" x-on:click="close()"></div>

    <aside class="absolute inset-x-0 bottom-0 flex max-h-[92vh] w-full flex-col overflow-hidden rounded-t-3xl bg-white shadow-2xl transition duration-200 ease-out sm:inset-y-0 sm:right-0 sm:left-auto sm:max-h-none sm:rounded-none sm:rounded-l-3xl {{ $width }}"
           :class="shown ? 'translate-y-0 sm:translate-x-0' : 'translate-y-full sm:translate-y-0 sm:translate-x-full'">
        <header class="flex items-start justify-between gap-4 border-b border-navy-100 px-6 py-4">
            <div class="min-w-0">
                <h2 class="truncate text-lg font-semibold text-navy-800">{{ $title }}</h2>
                @if ($subtitle)
                    <p class="mt-0.5 truncate text-sm text-navy-600">{{ $subtitle }}</p>
                @endif
            </div>
            <button type="button" x-on:click="close()" class="rounded-lg p-1.5 text-navy-600 hover:bg-navy-50" aria-label="{{ __('crm.common.close') }}">
                <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" d="M6 6l12 12M18 6 6 18"/></svg>
            </button>
        </header>

        <div x-show="asking" x-cloak class="flex flex-wrap items-center justify-between gap-3 border-b border-amber-200 bg-amber-50 px-6 py-3 text-sm text-amber-900" role="alert">
            <span>{{ __('crm.common.discard_changes') }}</span>
            <span class="flex gap-2">
                <button type="button" x-on:click="asking = false" class="crm-btn-secondary !py-1.5">{{ __('crm.common.keep_editing') }}</button>
                <button type="button" x-on:click="$wire.closeForm()" class="rounded-xl bg-red-600 px-4 py-1.5 text-sm font-semibold text-white hover:bg-red-700">{{ __('crm.common.discard') }}</button>
            </span>
        </div>

        <div class="min-h-0 flex-1 overflow-y-auto px-6 py-5">
            {{ $slot }}
        </div>
    </aside>
</div>
