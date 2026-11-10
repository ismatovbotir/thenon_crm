<?php

namespace App\Services\Telegram;

class InitDataValidator
{
    /**
     * The local Telegram simulator is available only on developer machines and only when switched on.
     */
    public static function mockEnabled(): bool
    {
        return (bool) config('telegram.mock') && app()->environment(['local', 'testing']);
    }

    /**
     * Bot token used to verify signatures; the simulator falls back to a fixed fake token.
     */
    public static function token(): ?string
    {
        return config('telegram.bot_token') ?: (self::mockEnabled() ? 'mock-bot-token' : null);
    }

    /**
     * Validate Telegram Web App initData and return the Telegram user id, or null when invalid.
     *
     * @see https://core.telegram.org/bots/webapps#validating-data-received-via-the-mini-app
     */
    public function telegramUserId(string $initData): ?int
    {
        $token = self::token();

        if (! $token || $initData === '') {
            return null;
        }

        parse_str($initData, $fields);

        $hash = $fields['hash'] ?? null;
        unset($fields['hash']);

        if (! is_string($hash) || collect($fields)->contains(fn ($value) => ! is_string($value))) {
            return null;
        }

        ksort($fields);
        $checkString = collect($fields)->map(fn ($value, $key) => $key.'='.$value)->implode("\n");

        $secret = hash_hmac('sha256', $token, 'WebAppData', true);

        if (! hash_equals(hash_hmac('sha256', $checkString, $secret), $hash)) {
            return null;
        }

        $authDate = (int) ($fields['auth_date'] ?? 0);

        if ($authDate <= 0 || time() - $authDate > config('telegram.init_data_ttl')) {
            return null;
        }

        $user = json_decode((string) ($fields['user'] ?? ''), true);

        return is_array($user) && isset($user['id']) ? (int) $user['id'] : null;
    }
}
