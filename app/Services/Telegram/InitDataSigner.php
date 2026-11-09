<?php

namespace App\Services\Telegram;

use App\Models\Employee;

/**
 * Builds signed Telegram Web App initData the way Telegram does. Used only by the local mock.
 */
class InitDataSigner
{
    public function sign(Employee $employee): string
    {
        // Unregistered employees get an id that matches nobody, so the app shows its "access denied" state.
        $telegramId = $employee->telegram_id ?? 900_000_000 + $employee->id;

        $fields = [
            'auth_date' => (string) time(),
            'query_id' => 'MOCK'.bin2hex(random_bytes(6)),
            'user' => json_encode([
                'id' => (int) $telegramId,
                'first_name' => $employee->name,
                'language_code' => $employee->language?->value ?? 'ru',
            ], JSON_UNESCAPED_UNICODE),
        ];

        ksort($fields);
        $check = collect($fields)->map(fn ($value, $key) => $key.'='.$value)->implode("\n");
        $secret = hash_hmac('sha256', (string) InitDataValidator::token(), 'WebAppData', true);
        $fields['hash'] = hash_hmac('sha256', $check, $secret);

        return http_build_query($fields);
    }
}
