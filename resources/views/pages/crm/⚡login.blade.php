<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;

new #[Layout('layouts::auth')] #[Title('CRM')] class extends Component
{
    #[Validate('required|email')]
    public string $email = '';

    #[Validate('required|string')]
    public string $password = '';

    public bool $remember = false;

    public function fillDemo(string $email): void
    {
        abort_unless(config('crm.demo_login'), 403);
        abort_unless(collect(config('crm.demo_users'))->contains('email', $email), 403);

        $this->email = $email;
        $this->password = (string) config('crm.seed_password');
        $this->resetErrorBag();
    }

    public function login(): void
    {
        $this->validate();

        $throttleKey = Str::transliterate(Str::lower($this->email).'|'.request()->ip());

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $seconds = RateLimiter::availableIn($throttleKey);

            $this->addError('email', trans('auth.throttle', ['seconds' => $seconds, 'minutes' => ceil($seconds / 60)]));

            return;
        }

        $authenticated = Auth::attempt(
            ['email' => $this->email, 'password' => $this->password, 'is_active' => true],
            $this->remember,
        );

        if (! $authenticated) {
            RateLimiter::hit($throttleKey);

            $this->addError('email', __('crm.login.failed'));

            return;
        }

        RateLimiter::clear($throttleKey);
        session()->regenerate();

        $this->redirectRoute('crm.dashboard');
    }
};
?>

<div class="space-y-4">
<div class="rounded-3xl bg-white p-8 shadow-2xl shadow-navy-950/40">
    <h1 class="text-2xl font-semibold text-navy-800">{{ __('crm.login.title') }}</h1>
    <p class="mt-1 text-sm text-navy-600">{{ __('crm.login.subtitle') }}</p>

    <form wire:submit="login" class="mt-8 space-y-5">
        <div>
            <label for="email" class="block text-sm font-medium text-navy-800">{{ __('crm.login.email') }}</label>
            <input id="email" type="email" wire:model="email" autocomplete="username" autofocus required
                   class="mt-1.5 block w-full rounded-xl border border-navy-100 bg-navy-50/50 px-4 py-3 text-navy-900 outline-none transition focus:border-gold-400 focus:bg-white focus:ring-4 focus:ring-gold-400/20">
            @error('email')
                <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label for="password" class="block text-sm font-medium text-navy-800">{{ __('crm.login.password') }}</label>
            <input id="password" type="password" wire:model="password" autocomplete="current-password" required
                   class="mt-1.5 block w-full rounded-xl border border-navy-100 bg-navy-50/50 px-4 py-3 text-navy-900 outline-none transition focus:border-gold-400 focus:bg-white focus:ring-4 focus:ring-gold-400/20">
            @error('password')
                <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p>
            @enderror
        </div>

        <label class="flex items-center gap-2 text-sm text-navy-700">
            <input type="checkbox" wire:model="remember" class="size-4 rounded border-navy-100 text-navy-800 focus:ring-gold-400">
            {{ __('crm.login.remember') }}
        </label>

        <button type="submit" wire:loading.attr="disabled"
                class="w-full rounded-xl bg-navy-800 px-4 py-3 font-semibold text-white transition hover:bg-navy-700 disabled:opacity-60">
            <span wire:loading.remove>{{ __('crm.login.submit') }}</span>
            <span wire:loading>…</span>
        </button>
    </form>
</div>

@if (config('crm.demo_login'))
    <div class="rounded-2xl border border-gold-400/40 bg-gold-400/10 p-5 text-white">
        <p class="text-sm font-semibold text-gold-400">{{ __('crm.login.demo_title') }}</p>
        <p class="mt-0.5 text-xs text-navy-100/80">{{ __('crm.login.demo_hint') }}</p>

        <ul class="mt-3 space-y-2">
            @foreach (config('crm.demo_users') as $demo)
                <li wire:key="demo-{{ $demo['role'] }}">
                    <button type="button" wire:click="fillDemo('{{ $demo['email'] }}')"
                            class="flex w-full items-center justify-between gap-3 rounded-xl bg-white/10 px-4 py-2.5 text-left text-sm transition hover:bg-white/20">
                        <span>
                            <span class="block font-medium">{{ \App\Enums\UserRole::from($demo['role'])->label() }}</span>
                            <span class="block text-xs text-navy-100/80">{{ $demo['email'] }}</span>
                        </span>
                        <code class="rounded-md bg-navy-950/50 px-2 py-1 text-xs text-gold-300">{{ config('crm.seed_password') }}</code>
                    </button>
                </li>
            @endforeach
        </ul>
    </div>
@endif
</div>
