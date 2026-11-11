@props(['label', 'name', 'hint' => null, 'required' => false])

<div {{ $attributes->class('space-y-1.5') }}>
    <label for="{{ $name }}" class="block text-sm font-medium text-navy-800">{{ $label }}@if ($required)<span class="ml-0.5 text-red-500" aria-hidden="true">*</span>@endif</label>
    {{ $slot }}
    @if ($hint)
        <p class="text-xs text-navy-600">{{ $hint }}</p>
    @endif
    @error($name)
        <p class="flex items-center gap-1 text-sm text-red-600" role="alert">
            <svg class="size-4 shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M18 10a8 8 0 1 1-16 0 8 8 0 0 1 16 0Zm-8-4a1 1 0 0 0-1 1v3a1 1 0 0 0 2 0V7a1 1 0 0 0-1-1Zm0 7a1 1 0 1 0 0 2 1 1 0 0 0 0-2Z" clip-rule="evenodd"/></svg>
            {{ $message }}
        </p>
    @enderror
</div>
