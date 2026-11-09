<?php

namespace App\Services\Telegram;

use App\Enums\Language;
use App\Models\Employee;
use Illuminate\Support\Facades\Cache;

class RegistrationFlow
{
    public function __construct(private readonly TelegramClient $telegram) {}

    /**
     * Handle one Telegram update (bot registration: language, then phone contact).
     *
     * @param  array<string, mixed>  $update
     */
    public function handle(array $update): void
    {
        if (isset($update['callback_query']) && is_array($update['callback_query'])) {
            $this->handleCallback($update['callback_query']);

            return;
        }

        $message = $update['message'] ?? null;

        if (! is_array($message) || ($message['chat']['type'] ?? null) !== 'private' || ! isset($message['from']['id'])) {
            return;
        }

        $chatId = (int) $message['chat']['id'];
        $fromId = (int) $message['from']['id'];

        if (isset($message['contact']) && is_array($message['contact'])) {
            $this->handleContact($chatId, $fromId, $message['contact']);

            return;
        }

        $employee = Employee::query()->where('telegram_id', $fromId)->first();

        if ($employee) {
            $this->welcome($chatId, $employee);

            return;
        }

        $this->askLanguage($chatId);
    }

    /**
     * @param  array<string, mixed>  $callback
     */
    private function handleCallback(array $callback): void
    {
        $data = (string) ($callback['data'] ?? '');
        $fromId = $callback['from']['id'] ?? null;
        $chatId = $callback['message']['chat']['id'] ?? $fromId;

        $this->telegram->answerCallbackQuery((string) ($callback['id'] ?? ''));

        if (! $fromId || ! $chatId || ! str_starts_with($data, 'lang:')) {
            return;
        }

        $language = Language::tryFrom(substr($data, 5));

        if (! $language) {
            return;
        }

        Cache::put($this->languageKey((int) $fromId), $language->value, now()->addDay());

        $this->telegram->sendMessage($chatId, $this->text('share_phone', $language), [
            'reply_markup' => [
                'keyboard' => [[['text' => $this->text('share_button', $language), 'request_contact' => true]]],
                'resize_keyboard' => true,
                'one_time_keyboard' => true,
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $contact
     */
    private function handleContact(int $chatId, int $fromId, array $contact): void
    {
        $language = Language::tryFrom((string) Cache::get($this->languageKey($fromId))) ?? Language::Ru;

        // Telegram only fills user_id when the person shared their own contact card.
        if ((int) ($contact['user_id'] ?? 0) !== $fromId) {
            $this->reply($chatId, 'own_phone_only', $language);

            return;
        }

        $employee = Employee::query()->where('phone', Employee::normalizePhone((string) ($contact['phone_number'] ?? '')))->first();

        if (! $employee) {
            $this->reply($chatId, 'not_found', $language);

            return;
        }

        if (! $employee->is_active) {
            $this->reply($chatId, 'deactivated', $language);

            return;
        }

        $takenByOther = $employee->telegram_id !== null && (int) $employee->telegram_id !== $fromId;
        $accountUsed = Employee::query()->where('telegram_id', $fromId)->whereKeyNot($employee->id)->exists();

        if ($takenByOther || $accountUsed) {
            $this->reply($chatId, 'already_linked', $language);

            return;
        }

        $employee->forceFill([
            'telegram_id' => $fromId,
            'language' => $language,
            'registered_at' => $employee->registered_at ?? now(),
        ])->save();

        Cache::forget($this->languageKey($fromId));

        $this->welcome($chatId, $employee);
    }

    private function welcome(int $chatId, Employee $employee): void
    {
        $language = $employee->language ?? Language::Ru;

        if (! $employee->is_active) {
            $this->reply($chatId, 'deactivated', $language);

            return;
        }

        $extra = ['reply_markup' => ['remove_keyboard' => true]];
        $url = config('telegram.web_app_url');

        if ($url) {
            $extra['reply_markup'] = ['inline_keyboard' => [[
                ['text' => $this->text('open_app', $language), 'web_app' => ['url' => $url]],
            ]]];
        }

        $this->telegram->sendMessage($chatId, $this->text('welcome', $language, ['name' => $employee->name]), $extra);
    }

    private function askLanguage(int $chatId): void
    {
        $this->telegram->sendMessage($chatId, 'Tilni tanlang / Выберите язык', [
            'reply_markup' => ['inline_keyboard' => [[
                ['text' => Language::Uz->label(), 'callback_data' => 'lang:uz'],
                ['text' => Language::Ru->label(), 'callback_data' => 'lang:ru'],
            ]]],
        ]);
    }

    private function reply(int $chatId, string $key, Language $language): void
    {
        $this->telegram->sendMessage($chatId, $this->text($key, $language), ['reply_markup' => ['remove_keyboard' => true]]);
    }

    /**
     * @param  array<string, string>  $replace
     */
    private function text(string $key, Language $language, array $replace = []): string
    {
        return __('telegram.'.$key, $replace, $language->value);
    }

    private function languageKey(int $telegramId): string
    {
        return 'telegram.language.'.$telegramId;
    }
}
