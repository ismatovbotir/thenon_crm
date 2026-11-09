<?php

namespace App\Http\Controllers;

use App\Http\Middleware\EnsureEmployeeAccess;
use App\Models\Company;
use App\Models\Employee;
use App\Services\Telegram\InitDataValidator;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Local-only Telegram Web App simulator: lets developers open /app in a normal browser as any employee.
 */
class DevTelegramController extends Controller
{
    public const SESSION_KEY = 'dev_mock_employee_id';

    public function index(Request $request): View
    {
        abort_unless(InitDataValidator::mockEnabled(), 404);

        $selected = $request->session()->get(self::SESSION_KEY);

        return view('dev.telegram', [
            'companies' => Company::query()->with(['employees' => fn ($query) => $query->orderBy('name')->with('locations')])->orderBy('name')->get(),
            'selected' => $selected ? Employee::query()->find($selected) : null,
        ]);
    }

    public function select(Request $request, Employee $employee): RedirectResponse
    {
        abort_unless(InitDataValidator::mockEnabled(), 404);

        // Forget any previous app session so the app signs in again as the newly chosen employee.
        $request->session()->forget(EnsureEmployeeAccess::SESSION_KEY);
        $request->session()->put(self::SESSION_KEY, $employee->id);

        return redirect()->route('dev.telegram');
    }

    public function stop(Request $request): RedirectResponse
    {
        abort_unless(InitDataValidator::mockEnabled(), 404);

        $request->session()->forget([self::SESSION_KEY, EnsureEmployeeAccess::SESSION_KEY]);

        return redirect()->route('dev.telegram');
    }
}
