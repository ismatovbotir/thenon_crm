<?php

namespace App\Http\Middleware;

use App\Http\Controllers\DevTelegramController;
use App\Models\Employee;
use App\Services\Telegram\InitDataSigner;
use App\Services\Telegram\InitDataValidator;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureEmployeeAccess
{
    public const SESSION_KEY = 'employee';

    /**
     * Re-check the employee on every request so deactivation locks them out instantly.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $employee = $this->employee($request);

        if (! $employee) {
            $request->session()->forget(self::SESSION_KEY);

            if ($request->expectsJson()) {
                return response()->json(['message' => 'Unauthenticated.'], 401);
            }

            return response()->view('app.gate', ['mockInitData' => $this->mockInitData($request)], 401);
        }

        $request->attributes->set('employee', $employee);

        if ($employee->language) {
            app()->setLocale($employee->language->value);
        }

        return $next($request);
    }

    private function mockInitData(Request $request): ?string
    {
        if (! InitDataValidator::mockEnabled()) {
            return null;
        }

        $employee = Employee::query()->find($request->session()->get(DevTelegramController::SESSION_KEY));

        return $employee ? app(InitDataSigner::class)->sign($employee) : null;
    }

    private function employee(Request $request): ?Employee
    {
        $stored = $request->session()->get(self::SESSION_KEY);

        if (! is_array($stored) || ! isset($stored['id'], $stored['telegram_id'])) {
            return null;
        }

        return Employee::query()
            ->whereKey($stored['id'])
            ->where('telegram_id', $stored['telegram_id'])
            ->where('is_active', true)
            ->first();
    }
}
