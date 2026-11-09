@props(['label', 'name', 'hint' => null])

<div {{ $attributes->class('space-y-1.5') }}>
    <label for="{{ $name }}" class="block text-sm font-medium text-navy-800">{{ $label }}</label>
    {{ $slot }}
    @if ($hint)
        <p class="text-xs text-navy-600">{{ $hint }}</p>
    @endif
    @error($name)
        <p class="text-sm text-red-600">{{ $message }}</p>
    @enderror
</div>
