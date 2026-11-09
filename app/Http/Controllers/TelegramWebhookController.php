<?php

namespace App\Http\Controllers;

use App\Services\Telegram\RegistrationFlow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TelegramWebhookController extends Controller
{
    public function __invoke(Request $request, RegistrationFlow $flow): JsonResponse
    {
        $secret = (string) config('telegram.webhook_secret');
        $provided = (string) $request->header('X-Telegram-Bot-Api-Secret-Token', '');

        abort_unless($secret !== '' && hash_equals($secret, $provided), 403);

        $flow->handle($request->json()->all());

        return response()->json(['ok' => true]);
    }
}
