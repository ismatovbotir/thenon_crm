<?php

namespace App\Http\Controllers;

use App\Http\Middleware\EnsureEmployeeAccess;
use App\Models\Employee;
use App\Services\Telegram\InitDataValidator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmployeeAppController extends Controller
{
    /**
     * Exchange signed Telegram initData for an employee session.
     */
    public function authenticate(Request $request, InitDataValidator $validator): JsonResponse
    {
        $telegramId = $validator->telegramUserId((string) $request->input('init_data', ''));

        $employee = $telegramId
            ? Employee::query()->where('telegram_id', $telegramId)->where('is_active', true)->first()
            : null;

        if (! $employee) {
            $request->session()->forget(EnsureEmployeeAccess::SESSION_KEY);

            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $request->session()->regenerate();
        $request->session()->put(EnsureEmployeeAccess::SESSION_KEY, [
            'id' => $employee->id,
            'telegram_id' => $employee->telegram_id,
        ]);

        return response()->json(['ok' => true]);
    }
}
