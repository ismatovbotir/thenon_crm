<?php

namespace App\Jobs;

use App\Models\Order;
use App\Services\Telegram\TelegramClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;

class SendTelegramMessage implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * @param  string|null  $webAppButton  Label of an inline button that opens the ordering web app.
     * @param  int|null  $adminOrderId  Order whose admin_notified_at is stamped once Telegram accepts the message.
     */
    public function __construct(
        public int $chatId,
        public string $text,
        public ?string $webAppButton = null,
        public ?int $adminOrderId = null,
    ) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 60];
    }

    public function handle(TelegramClient $telegram): void
    {
        if (! $telegram->isConfigured()) {
            return;
        }

        $extra = [];
        $url = config('telegram.web_app_url');

        if ($this->webAppButton && $url) {
            $extra['reply_markup'] = ['inline_keyboard' => [[
                ['text' => $this->webAppButton, 'web_app' => ['url' => $url]],
            ]]];
        }

        if (! $telegram->sendMessage($this->chatId, $this->text, $extra)) {
            throw new RuntimeException('Telegram did not accept the message.');
        }

        if ($this->adminOrderId) {
            Order::query()->whereKey($this->adminOrderId)->toBase()->update(['admin_notified_at' => now()]);
        }
    }
}
