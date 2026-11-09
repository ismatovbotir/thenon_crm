<?php

return [

    /*
    | Bot credentials. The token comes from BotFather and must only live in .env.
    */
    'bot_token' => env('TELEGRAM_BOT_TOKEN'),

    /*
    | Local simulator of the Telegram Web App (/dev/telegram). Ignored outside local/testing environments.
    */
    'mock' => (bool) env('TELEGRAM_MOCK', false),

    'bot_username' => env('TELEGRAM_BOT_USERNAME', 'TheNonuzBot'),

    /*
    | Sent back by Telegram in the X-Telegram-Bot-Api-Secret-Token header so we
    | can reject webhook calls that did not come from Telegram.
    */
    'webhook_secret' => env('TELEGRAM_WEBHOOK_SECRET'),

    /*
    | Telegram chat id of the administrator who receives a text copy of every completed order.
    | The admin must have started the bot first. Leave empty to disable.
    */
    'admin_chat_id' => env('TELEGRAM_ADMIN_ID'),

    /*
    | Public https URL of the ordering web app opened from the bot.
    */
    'web_app_url' => env('TELEGRAM_WEB_APP_URL'),

    /*
    | How long (seconds) signed initData stays valid.
    */
    'init_data_ttl' => 86400,

];
