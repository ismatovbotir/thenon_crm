<?php

namespace App\Console\Commands;

use App\Services\Telegram\TelegramClient;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('telegram:webhook {url? : Public https base URL, defaults to APP_URL}')]
#[Description('Register the Telegram bot webhook')]
class SetTelegramWebhook extends Command
{
    public function handle(TelegramClient $telegram): int
    {
        $secret = (string) config('telegram.webhook_secret');

        if (! config('telegram.bot_token') || $secret === '') {
            $this->error('Set TELEGRAM_BOT_TOKEN and TELEGRAM_WEBHOOK_SECRET in .env first.');

            return self::FAILURE;
        }

        $base = rtrim((string) ($this->argument('url') ?: config('app.url')), '/');

        if (! str_starts_with($base, 'https://')) {
            $this->error('Telegram requires a public https URL (use a tunnel such as ngrok for local work).');

            return self::FAILURE;
        }

        if (! $telegram->setWebhook($base.'/telegram/webhook', $secret)) {
            $this->error('Telegram rejected the webhook. Check the token and URL.');

            return self::FAILURE;
        }

        $this->info("Webhook set to {$base}/telegram/webhook");

        return self::SUCCESS;
    }
}
