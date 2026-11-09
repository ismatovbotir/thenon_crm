@props(['on' => false, 'label'])

<span title="{{ $label }}" @class([
    'crm-badge gap-1 whitespace-nowrap',
    'bg-emerald-100 text-emerald-700' => $on,
    'bg-navy-100 text-navy-600' => ! $on,
])>
    <svg class="size-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3" aria-hidden="true">
        @if ($on)
            <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
        @else
            <path stroke-linecap="round" d="M6 12h12"/>
        @endif
    </svg>
    {{ $label }}
</span>
