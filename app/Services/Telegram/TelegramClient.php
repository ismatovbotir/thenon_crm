<?php

namespace App\Services\Telegram;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class TelegramClient
{
    public function isConfigured(): bool
    {
        return (bool) config('telegram.bot_token');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function call(string $method, array $payload = []): bool
    {
        $token = config('telegram.bot_token');

        if (! $token) {
            return false;
        }

        return $this->http()->post("https://api.telegram.org/bot{$token}/{$method}", $payload)->successful();
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    public function sendMessage(int|string $chatId, string $text, array $extra = []): bool
    {
        return $this->call('sendMessage', ['chat_id' => $chatId, 'text' => $text] + $extra);
    }

    public function answerCallbackQuery(string $callbackId): bool
    {
        return $this->call('answerCallbackQuery', ['callback_query_id' => $callbackId]);
    }

    public function setWebhook(string $url, string $secret): bool
    {
        return $this->call('setWebhook', [
            'url' => $url,
            'secret_token' => $secret,
            'allowed_updates' => ['message', 'callback_query'],
        ]);
    }

    private function http(): PendingRequest
    {
        return Http::timeout(10)->retry(2, 200, throw: false);
    }
}
