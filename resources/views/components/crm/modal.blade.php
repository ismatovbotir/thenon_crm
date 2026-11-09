@props(['title', 'maxWidth' => 'max-w-lg'])

<div class="fixed inset-0 z-[60] flex items-end justify-center p-0 sm:items-center sm:p-4"
     role="dialog" aria-modal="true" aria-label="{{ $title }}"
     x-data x-on:keydown.escape.window="$wire.closeForm()">
    <div class="absolute inset-0 bg-navy-950/60" wire:click="closeForm"></div>

    <div class="relative max-h-[92vh] w-full {{ $maxWidth }} overflow-y-auto rounded-t-3xl bg-white p-6 shadow-2xl sm:rounded-3xl">
        <div class="mb-5 flex items-start justify-between gap-4">
            <h2 class="text-xl font-semibold text-navy-800">{{ $title }}</h2>
            <button type="button" wire:click="closeForm" class="rounded-lg p-1.5 text-navy-600 hover:bg-navy-50" aria-label="{{ __('crm.common.close') }}">
                <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" d="M6 6l12 12M18 6 6 18"/></svg>
            </button>
        </div>

        {{ $slot }}
    </div>
</div>
