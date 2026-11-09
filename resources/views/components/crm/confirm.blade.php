@props(['message', 'action', 'danger' => false])

{{-- Button that asks for confirmation in an in-page modal (instead of the browser's confirm() window), then runs the Livewire action. --}}
<div x-data="{ open: false }" class="contents">
    <button type="button" x-on:click="open = true" {{ $attributes }}>{{ $slot }}</button>

    <div>
        <div x-cloak x-show="open" x-transition.opacity class="fixed inset-0 z-[70] flex items-end justify-center p-0 sm:items-center sm:p-4"
             role="dialog" aria-modal="true" aria-label="{{ __('crm.common.confirm_title') }}"
             x-on:keydown.escape.window="open = false">
            <div class="absolute inset-0 bg-navy-950/60" x-on:click="open = false"></div>

            <div class="relative w-full max-w-sm rounded-t-3xl bg-white p-6 shadow-2xl sm:rounded-3xl">
                <h2 class="text-lg font-semibold text-navy-800">{{ __('crm.common.confirm_title') }}</h2>
                <p class="mt-2 text-sm text-navy-600">{{ $message }}</p>

                <div class="mt-6 flex justify-end gap-2">
                    <button type="button" x-on:click="open = false" class="crm-btn-ghost">{{ __('crm.common.cancel') }}</button>
                    <button type="button" wire:click="{{ $action }}" x-on:click="open = false"
                            @class(['rounded-xl px-4 py-2 text-sm font-semibold text-white', 'bg-red-600 hover:bg-red-700' => $danger, 'bg-navy-800 hover:bg-navy-900' => ! $danger])>{{ __('crm.common.confirm_yes') }}</button>
                </div>
            </div>
        </div>
    </div>
</div>
