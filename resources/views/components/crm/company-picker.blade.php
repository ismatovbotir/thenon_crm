@props(['companies', 'model' => 'companyId'])

{{-- Searchable company filter: type to narrow a long list. Writes the chosen id ('' = all) to the page's $model property. --}}
<div
    x-data="{
        open: false,
        query: '',
        active: 0,
        options: @js($companies->map(fn ($company) => ['id' => (string) $company->id, 'name' => $company->name])->values()),
        get current() {
            return this.options.find((option) => option.id === String($wire.{{ $model }} ?? ''))
        },
        get matches() {
            const needle = this.query.trim().toLowerCase()
            return needle === '' ? this.options : this.options.filter((option) => option.name.toLowerCase().includes(needle))
        },
        choose(id) {
            $wire.$set('{{ $model }}', id)
            this.open = false
            this.query = ''
        },
        show() {
            this.open = true
            this.active = 0
            this.$nextTick(() => this.$refs.search.focus())
        },
    }"
    x-on:keydown.escape.window="open = false"
    x-on:click.outside="open = false"
    class="relative sm:w-64"
>
    <button type="button" x-on:click="open ? open = false : show()" aria-haspopup="listbox" x-bind:aria-expanded="open"
        class="crm-input flex w-full items-center justify-between gap-2 text-left">
        <span class="truncate" x-text="current ? current.name : @js(__('crm.orders.all_companies'))"></span>
        <svg class="size-4 shrink-0 text-navy-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m6 9 6 6 6-6"/></svg>
    </button>

    <div x-show="open" x-cloak x-transition.opacity class="absolute left-0 z-30 mt-1 w-full min-w-64 rounded-xl border border-navy-100 bg-white p-2 shadow-lg">
        <input type="search" x-ref="search" x-model="query" autocomplete="off"
            x-on:input="active = query.trim() === '' ? 0 : 1"
            x-on:keydown.arrow-down.prevent="active = Math.min(active + 1, matches.length)"
            x-on:keydown.arrow-up.prevent="active = Math.max(active - 1, 0)"
            x-on:keydown.enter.prevent="choose(active === 0 ? '' : matches[active - 1].id)"
            placeholder="{{ __('crm.common.search') }}" aria-label="{{ __('crm.nav.companies') }}" class="crm-input mb-2 w-full">

        <ul role="listbox" class="max-h-64 overflow-y-auto text-sm">
            <li>
                <button type="button" role="option" x-on:click="choose('')" x-on:mouseenter="active = 0"
                    x-bind:class="active === 0 ? 'bg-navy-50' : ''" class="w-full rounded-lg px-3 py-2 text-left font-medium text-navy-700 hover:bg-navy-50">
                    {{ __('crm.orders.all_companies') }}
                </button>
            </li>
            <template x-for="(option, index) in matches" x-bind:key="option.id">
                <li>
                    <button type="button" role="option" x-on:click="choose(option.id)" x-on:mouseenter="active = index + 1"
                        x-bind:class="active === index + 1 ? 'bg-navy-50' : ''" class="w-full rounded-lg px-3 py-2 text-left text-navy-800 hover:bg-navy-50">
                        <span x-text="option.name"></span>
                    </button>
                </li>
            </template>
            <li x-show="matches.length === 0" class="px-3 py-2 text-navy-500">—</li>
        </ul>
    </div>
</div>
