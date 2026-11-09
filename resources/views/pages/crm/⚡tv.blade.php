<?php

use App\Models\Company;
use App\Models\TvScreen;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts::crm')] class extends Component
{
    public string $name = '';

    public ?int $companyId = null;

    public function mount(): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);
    }

    public function rendering($view): void
    {
        $view->title(__('crm.tv.title'));
    }

    /**
     * @return Collection<int, TvScreen>
     */
    #[Computed]
    public function screens(): Collection
    {
        return TvScreen::query()->with('company')->latest('id')->get();
    }

    /**
     * @return Collection<int, Company>
     */
    #[Computed]
    public function companies(): Collection
    {
        return Company::query()->orderBy('name')->get();
    }

    public function create(): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);

        $data = $this->validate([
            'name' => ['required', 'string', 'max:100'],
            'companyId' => ['nullable', 'integer', 'exists:companies,id'],
        ], attributes: ['name' => __('crm.tv.name'), 'companyId' => __('crm.tv.company')]);

        TvScreen::query()->create([
            'name' => trim($data['name']),
            'company_id' => $data['companyId'] ?? null,
            'token' => TvScreen::newToken(),
        ]);

        $this->reset('name', 'companyId');
        unset($this->screens);
    }

    public function toggle(int $id): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);

        $screen = TvScreen::query()->findOrFail($id);
        $screen->update(['is_active' => ! $screen->is_active]);
        unset($this->screens);
    }

    public function regenerate(int $id): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);

        TvScreen::query()->findOrFail($id)->update(['token' => TvScreen::newToken()]);
        unset($this->screens);
    }

    public function remove(int $id): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);

        TvScreen::query()->whereKey($id)->delete();
        unset($this->screens);
    }
};
?>

<div class="w-full space-y-6">
    <h2 class="text-2xl font-semibold text-navy-800">{{ __('crm.tv.title') }}</h2>

    <section class="crm-card space-y-4 p-5">
        <p class="text-sm text-navy-600">{{ __('crm.tv.text') }}</p>

        <form wire:submit="create" class="grid gap-3 sm:grid-cols-[1fr_16rem_auto] sm:items-start">
            <div>
                <input type="text" wire:model="name" placeholder="{{ __('crm.tv.name') }}" class="crm-input">
                @error('name')
                    <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                @enderror
            </div>
            <div>
                <select wire:model="companyId" aria-label="{{ __('crm.tv.company') }}" class="crm-input">
                    <option value="">{{ __('crm.tv.all_companies') }}</option>
                    @foreach ($this->companies as $company)
                        <option value="{{ $company->id }}" wire:key="company-{{ $company->id }}">{{ $company->name }}</option>
                    @endforeach
                </select>
                @error('companyId')
                    <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                @enderror
            </div>
            <button type="submit" class="crm-btn-primary">{{ __('crm.tv.create') }}</button>
        </form>
    </section>

    <section class="crm-card divide-y divide-navy-100">
        @forelse ($this->screens as $screen)
            <div wire:key="screen-{{ $screen->id }}" class="space-y-3 p-5" x-data="{ copied: false }">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <p class="font-semibold text-navy-800">{{ $screen->name }}
                            @unless ($screen->is_active)
                                <span class="crm-badge ml-2 bg-navy-100 text-navy-600">{{ __('crm.tv.disabled') }}</span>
                            @endunless
                        </p>
                        <p class="text-xs text-navy-600">
                            {{ $screen->company?->name ?? __('crm.tv.all_companies') }} ·
                            {{ __('crm.tv.last_seen') }}: {{ $screen->last_seen_at?->format('d.m.Y H:i') ?? __('crm.tv.never') }}
                        </p>
                    </div>
                    <div class="flex flex-wrap items-center gap-1">
                        <a href="{{ $screen->url() }}" target="_blank" rel="noopener" class="crm-btn-ghost">{{ __('crm.tv.open') }}</a>
                        <button type="button" wire:click="toggle({{ $screen->id }})" class="crm-btn-ghost">{{ $screen->is_active ? __('crm.tv.disable') : __('crm.tv.enable') }}</button>
                        <x-crm.confirm action="regenerate({{ $screen->id }})" :message="__('crm.tv.regenerate_confirm')" class="crm-btn-ghost">{{ __('crm.tv.regenerate') }}</x-crm.confirm>
                        <x-crm.confirm action="remove({{ $screen->id }})" :message="__('crm.tv.delete_confirm')" danger class="crm-btn-ghost text-red-600">{{ __('crm.tv.delete') }}</x-crm.confirm>
                    </div>
                </div>

                <div class="flex items-center gap-2">
                    <code class="min-w-0 flex-1 break-all rounded-lg bg-navy-50 px-3 py-2 font-mono text-xs text-navy-900">{{ $screen->url() }}</code>
                    <button type="button" class="crm-btn-secondary shrink-0"
                            x-on:click="navigator.clipboard.writeText(@js($screen->url())).then(() => { copied = true; setTimeout(() => copied = false, 1500) })"
                            x-text="copied ? @js(__('crm.tv.copied')) : @js(__('crm.tv.copy'))"></button>
                </div>
            </div>
        @empty
            <p class="p-8 text-center text-sm text-navy-600">{{ __('crm.tv.empty') }}</p>
        @endforelse
    </section>
</div>
