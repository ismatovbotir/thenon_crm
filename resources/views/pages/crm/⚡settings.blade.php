<?php

use App\Models\ApiToken;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts::crm')] class extends Component
{
    public string $tokenName = '';

    public ?string $newToken = null;

    public function mount(): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);
    }

    public function rendering($view): void
    {
        $view->title(__('crm.settings.title'));
    }

    /**
     * @return Collection<int, ApiToken>
     */
    #[Computed]
    public function tokens(): Collection
    {
        return ApiToken::query()->latest('id')->get();
    }

    public function createToken(): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);

        $data = $this->validate(
            ['tokenName' => ['required', 'string', 'max:100']],
            attributes: ['tokenName' => __('crm.settings.token_name')],
        );

        [, $plain] = ApiToken::issue(trim($data['tokenName']));

        $this->newToken = $plain;
        $this->reset('tokenName');
        unset($this->tokens);
    }

    public function revoke(int $id): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);

        ApiToken::query()->whereKey($id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
        unset($this->tokens);
    }
};
?>

<div class="mx-auto max-w-4xl space-y-6">
    <h2 class="text-2xl font-semibold text-navy-800">{{ __('crm.settings.title') }}</h2>

    <section class="crm-card space-y-4 p-5">
        <div>
            <h3 class="font-semibold text-navy-800">{{ __('crm.settings.api_title') }}</h3>
            <p class="mt-1 text-sm text-navy-600">{{ __('crm.settings.api_text') }}</p>
        </div>

        <form wire:submit="createToken" class="flex flex-col gap-3 sm:flex-row sm:items-start">
            <div class="flex-1">
                <input type="text" wire:model="tokenName" placeholder="{{ __('crm.settings.token_name') }}" class="crm-input">
                @error('tokenName')
                    <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                @enderror
            </div>
            <button type="submit" class="crm-btn-primary">{{ __('crm.settings.create_token') }}</button>
        </form>

        @if ($newToken)
            <div class="rounded-xl bg-gold-300/25 p-4">
                <p class="text-sm font-medium text-crust-700">{{ __('crm.settings.new_token') }}</p>
                <code class="mt-2 block break-all rounded-lg bg-white px-3 py-2 font-mono text-sm text-navy-900">{{ $newToken }}</code>
            </div>
        @endif

        <div>
            <h4 class="text-sm font-semibold uppercase tracking-wide text-navy-600">{{ __('crm.settings.tokens') }}</h4>
            @forelse ($this->tokens as $token)
                <div wire:key="token-{{ $token->id }}" class="flex flex-wrap items-center justify-between gap-3 border-b border-navy-100 py-3 last:border-0">
                    <div>
                        <p class="font-medium text-navy-800">{{ $token->name }}</p>
                        <p class="text-xs text-navy-600">{{ __('crm.settings.last_used') }}: {{ $token->last_used_at?->format('d.m.Y H:i') ?? __('crm.settings.never') }}</p>
                    </div>
                    @if ($token->isRevoked())
                        <span class="crm-badge bg-navy-100 text-navy-600">{{ __('crm.settings.revoked') }}</span>
                    @else
                        <button type="button" wire:click="revoke({{ $token->id }})" wire:confirm="{{ __('crm.settings.revoke') }}?" class="crm-btn-ghost text-red-600">{{ __('crm.settings.revoke') }}</button>
                    @endif
                </div>
            @empty
                <p class="py-4 text-sm text-navy-600">{{ __('crm.settings.no_tokens') }}</p>
            @endforelse
        </div>
    </section>

    <section class="crm-card p-5">
        <h3 class="font-semibold text-navy-800">{{ __('crm.settings.endpoints') }}</h3>
        <ul class="mt-3 space-y-2 font-mono text-xs text-navy-800">
            <li>GET {{ url('/api/v1/orders') }}?date=YYYY-MM-DD&amp;status=&amp;company_id=&amp;updated_since=</li>
            <li>GET {{ url('/api/v1/summary') }}?date=YYYY-MM-DD</li>
        </ul>
    </section>
</div>
