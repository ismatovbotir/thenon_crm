<?php

namespace App\Services\Telegram;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class TelegramClient
{
    /**
     * Why the most recent call failed (Telegram's own description when available).
     */
    public ?string $lastError = null;

    public function isConfigured(): bool
    {
        return (bool) config('telegram.bot_token');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function call(string $method, array $payload = []): bool
    {
        $this->lastError = null;
        $token = config('telegram.bot_token');

        if (! $token) {
            $this->lastError = 'Bot token is not configured.';

            return false;
        }

        try {
            $response = $this->http()->post("https://api.telegram.org/bot{$token}/{$method}", $payload);
        } catch (ConnectionException $exception) {
            $this->lastError = 'Connection to Telegram failed: '.str_replace($token, '***', $exception->getMessage());

            return false;
        }

        if (! $response->successful()) {
            $this->lastError = 'Telegram error '.($response->json('error_code') ?? $response->status())
                .': '.($response->json('description') ?? mb_substr($response->body(), 0, 300));
        }

        return $response->successful();
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
