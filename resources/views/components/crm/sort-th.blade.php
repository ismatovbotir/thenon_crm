@props(['column', 'sort', 'dir'])

{{-- Sortable table header: calls the page's sortBy() and shows the direction. --}}
<th {{ $attributes->class('crm-th') }} aria-sort="{{ $sort === $column ? ($dir === 'asc' ? 'ascending' : 'descending') : 'none' }}">
    <button type="button" wire:click="sortBy('{{ $column }}')" class="inline-flex items-center gap-1 uppercase tracking-wide hover:text-navy-950">
        {{ $slot }}
        <span @class(['text-[10px]', 'text-gold-600' => $sort === $column, 'text-navy-100' => $sort !== $column])>{{ $sort === $column ? ($dir === 'asc' ? '▲' : '▼') : '↕' }}</span>
    </button>
</th>
