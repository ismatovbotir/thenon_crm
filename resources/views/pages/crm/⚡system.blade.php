<?php

use App\Services\CatalogCache;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts::crm')] class extends Component
{
    /**
     * The only commands this page can run. Nothing the browser sends is ever used as a command name or option.
     *
     * @var array<string, array{command: string, options: array<string, mixed>, danger: bool}>
     */
    private const COMMANDS = [
        'queue-work' => ['command' => 'queue:work', 'options' => ['--stop-when-empty' => true, '--max-jobs' => 50, '--max-time' => 20, '--memory' => 256, '--tries' => 3], 'danger' => false],
        'remind-cutoff' => ['command' => 'orders:remind-cutoff', 'options' => [], 'danger' => false],
        'schedule-run' => ['command' => 'schedule:run', 'options' => [], 'danger' => false],
        'queue-retry-all' => ['command' => 'queue:retry', 'options' => ['id' => ['all']], 'danger' => false],
        'queue-restart' => ['command' => 'queue:restart', 'options' => [], 'danger' => false],
        'queue-flush' => ['command' => 'queue:flush', 'options' => [], 'danger' => true],
    ];

    public ?string $output = null;

    public ?string $ranLabel = null;

    public ?bool $ranOk = null;

    public function mount(): void
    {
        $this->ensureAdmin();
    }

    public function rendering($view): void
    {
        $view->title(__('crm.system.title'));
    }

    /**
     * @return list<string>
     */
    public function commandKeys(): array
    {
        return [...array_keys(self::COMMANDS), 'catalog-refresh'];
    }

    public function isDangerous(string $key): bool
    {
        return self::COMMANDS[$key]['danger'] ?? false;
    }

    /**
     * Waiting / running / delayed counts of the queue, the age of the oldest waiting job and the failed total.
     *
     * @return array{waiting: int, running: int, delayed: int, failed: int, oldest_wait: int|null}
     */
    #[Computed]
    public function stats(): array
    {
        $now = now()->timestamp;
        $row = Schema::hasTable($this->jobsTable()) ? DB::table($this->jobsTable())
            ->selectRaw(
                'count(*) as jobs_total,'
                .' sum(case when reserved_at is not null then 1 else 0 end) as jobs_running,'
                .' sum(case when reserved_at is null and available_at > ? then 1 else 0 end) as jobs_delayed,'
                .' min(case when reserved_at is null and available_at <= ? then available_at end) as jobs_oldest',
                [$now, $now],
            )->first() : null;

        $running = (int) ($row->jobs_running ?? 0);
        $delayed = (int) ($row->jobs_delayed ?? 0);

        return [
            'waiting' => (int) ($row->jobs_total ?? 0) - $running - $delayed,
            'running' => $running,
            'delayed' => $delayed,
            'failed' => Schema::hasTable($this->failedTable()) ? DB::table($this->failedTable())->count() : 0,
            'oldest_wait' => isset($row->jobs_oldest) ? max(0, $now - (int) $row->jobs_oldest) : null,
        ];
    }

    /**
     * @return list<array{id: int, name: string, queue: string, attempts: int, state: string, created: Carbon}>
     */
    #[Computed]
    public function pendingJobs(): array
    {
        if (! Schema::hasTable($this->jobsTable())) {
            return [];
        }

        $now = now()->timestamp;

        return DB::table($this->jobsTable())->orderByDesc('id')->limit(25)->get()->map(fn (object $job): array => [
            'id' => (int) $job->id,
            'name' => $this->jobName($job->payload),
            'queue' => (string) $job->queue,
            'attempts' => (int) $job->attempts,
            'state' => $job->reserved_at !== null ? 'running' : ((int) $job->available_at > $now ? 'delayed' : 'waiting'),
            'created' => Carbon::createFromTimestamp((int) $job->created_at),
        ])->all();
    }

    /**
     * @return list<array{uuid: string, name: string, queue: string, failed_at: Carbon, error: string}>
     */
    #[Computed]
    public function failedJobs(): array
    {
        if (! Schema::hasTable($this->failedTable())) {
            return [];
        }

        return DB::table($this->failedTable())->orderByDesc('id')->limit(25)->get()->map(fn (object $job): array => [
            'uuid' => (string) $job->uuid,
            'name' => $this->jobName($job->payload),
            'queue' => (string) $job->queue,
            'failed_at' => Carbon::parse($job->failed_at),
            'error' => Str::limit((string) strtok((string) $job->exception, "\n"), 220),
        ])->all();
    }

    public function runCommand(string $key): void
    {
        $this->ensureAdmin();

        if ($key === 'catalog-refresh') {
            app(CatalogCache::class)->flush();
            $this->finish($key, true, __('crm.system.catalog_refreshed'));

            return;
        }

        $spec = self::COMMANDS[$key] ?? null;
        abort_if($spec === null, 404);

        $this->execute($key, $spec['command'], $spec['options']);
    }

    public function retryFailed(string $uuid): void
    {
        $this->ensureAdmin();
        abort_unless(Str::isUuid($uuid), 422);

        $this->execute('queue-retry', 'queue:retry', ['id' => [$uuid]]);
    }

    public function forgetFailed(string $uuid): void
    {
        $this->ensureAdmin();
        abort_unless(Str::isUuid($uuid), 422);

        $this->execute('queue-forget', 'queue:forget', ['id' => $uuid]);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function execute(string $label, string $command, array $options): void
    {
        $lock = Cache::lock('crm-system-command', 60);

        if (! $lock->get()) {
            $this->finish($label, false, __('crm.system.busy'));

            return;
        }

        try {
            Log::notice('CRM system command', ['user_id' => auth()->id(), 'command' => $command, 'options' => $options]);

            $code = Artisan::call($command, $options);

            $this->finish($label, $code === 0, trim(Artisan::output()));
        } catch (Throwable $exception) {
            report($exception);

            $this->finish($label, false, $exception->getMessage());
        } finally {
            $lock->release();
        }
    }

    private function finish(string $label, bool $ok, string $output): void
    {
        $this->ranLabel = $label;
        $this->ranOk = $ok;
        $this->output = Str::limit($output !== '' ? $output : __('crm.system.no_output'), 4000);

        unset($this->stats, $this->pendingJobs, $this->failedJobs);
    }

    private function ensureAdmin(): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);
    }

    private function jobsTable(): string
    {
        return (string) config('queue.connections.database.table', 'jobs');
    }

    private function failedTable(): string
    {
        return (string) config('queue.failed.table', 'failed_jobs');
    }

    private function jobName(string $payload): string
    {
        $name = json_decode($payload, true)['displayName'] ?? null;

        return is_string($name) ? class_basename($name) : '—';
    }
};
?>

<div class="w-full space-y-6" wire:poll.10s>
    @php($stats = $this->stats)

    @if ($stats['oldest_wait'] !== null && $stats['oldest_wait'] > 120)
        <div class="rounded-2xl bg-amber-100 px-4 py-3 text-sm text-amber-900">
            {{ __('crm.system.worker_warning', ['minutes' => max(1, intdiv($stats['oldest_wait'], 60))]) }}
        </div>
    @endif

    <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
        @foreach (['waiting' => 'bg-navy-50 text-navy-800', 'running' => 'bg-sky-100 text-sky-800', 'delayed' => 'bg-amber-100 text-amber-800', 'failed' => $stats['failed'] > 0 ? 'bg-red-100 text-red-700' : 'bg-emerald-100 text-emerald-700'] as $key => $tone)
            <div class="crm-card p-4" wire:key="stat-{{ $key }}">
                <p class="text-xs font-medium uppercase tracking-wide text-navy-600">{{ __('crm.system.'.$key) }}</p>
                <p class="mt-2 inline-block rounded-lg px-3 py-1 text-2xl font-semibold {{ $tone }}">{{ $stats[$key] }}</p>
            </div>
        @endforeach
    </div>

    <section class="crm-card space-y-4 p-5">
        <div>
            <h3 class="font-semibold text-navy-800">{{ __('crm.system.commands') }}</h3>
            <p class="mt-1 text-sm text-navy-600">{{ __('crm.system.commands_text') }}</p>
        </div>
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($this->commandKeys() as $key)
                <button type="button" wire:key="cmd-{{ $key }}" wire:click="runCommand('{{ $key }}')" wire:loading.attr="disabled"
                        wire:confirm="{{ __('crm.system.confirm', ['name' => __('crm.system.cmd.'.$key.'.name')]) }}"
                        @class(['rounded-xl border p-3 text-left transition hover:bg-navy-50 disabled:opacity-60', 'border-red-200' => $this->isDangerous($key), 'border-navy-100' => ! $this->isDangerous($key)])>
                    <span @class(['block text-sm font-semibold', 'text-red-700' => $this->isDangerous($key), 'text-navy-800' => ! $this->isDangerous($key)])>{{ __('crm.system.cmd.'.$key.'.name') }}</span>
                    <span class="mt-0.5 block text-xs text-navy-600">{{ __('crm.system.cmd.'.$key.'.hint') }}</span>
                </button>
            @endforeach
        </div>

        @if ($output !== null)
            <div @class(['rounded-xl p-4', 'bg-emerald-50' => $ranOk, 'bg-red-50' => ! $ranOk])>
                <p @class(['text-sm font-semibold', 'text-emerald-800' => $ranOk, 'text-red-700' => ! $ranOk])>
                    {{ $ranOk ? __('crm.system.done') : __('crm.system.failed_run') }}
                </p>
                <pre class="mt-2 max-h-64 overflow-auto whitespace-pre-wrap break-words font-mono text-xs text-navy-800">{{ $output }}</pre>
            </div>
        @endif
    </section>

    <section class="crm-card overflow-hidden">
        <h3 class="border-b border-navy-100 px-5 py-4 font-semibold text-navy-800">{{ __('crm.system.pending_title') }}</h3>
        @if ($this->pendingJobs === [])
            <p class="p-8 text-center text-sm text-navy-600">{{ __('crm.system.pending_empty') }}</p>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-navy-100">
                    <thead class="bg-navy-50/60">
                        <tr>
                            <th class="crm-th">#</th>
                            <th class="crm-th">{{ __('crm.system.job') }}</th>
                            <th class="crm-th">{{ __('crm.system.queue') }}</th>
                            <th class="crm-th">{{ __('crm.system.attempts') }}</th>
                            <th class="crm-th">{{ __('crm.common.status') }}</th>
                            <th class="crm-th">{{ __('crm.system.created') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-navy-100">
                        @foreach ($this->pendingJobs as $job)
                            <tr wire:key="pending-{{ $job['id'] }}">
                                <td class="crm-td font-mono text-xs">{{ $job['id'] }}</td>
                                <td class="crm-td font-medium">{{ $job['name'] }}</td>
                                <td class="crm-td">{{ $job['queue'] }}</td>
                                <td class="crm-td">{{ $job['attempts'] }}</td>
                                <td class="crm-td"><span class="crm-badge bg-navy-100 text-navy-800">{{ __('crm.system.'.$job['state']) }}</span></td>
                                <td class="crm-td">{{ $job['created']->format('d.m.Y H:i:s') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    <section class="crm-card overflow-hidden">
        <h3 class="border-b border-navy-100 px-5 py-4 font-semibold text-navy-800">{{ __('crm.system.failed_title') }}</h3>
        @if ($this->failedJobs === [])
            <p class="p-8 text-center text-sm text-navy-600">{{ __('crm.system.failed_empty') }}</p>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-navy-100">
                    <thead class="bg-navy-50/60">
                        <tr>
                            <th class="crm-th">{{ __('crm.system.job') }}</th>
                            <th class="crm-th">{{ __('crm.system.error') }}</th>
                            <th class="crm-th">{{ __('crm.system.failed_at') }}</th>
                            <th class="crm-th text-right">{{ __('crm.common.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-navy-100">
                        @foreach ($this->failedJobs as $job)
                            <tr wire:key="failed-{{ $job['uuid'] }}">
                                <td class="crm-td font-medium">{{ $job['name'] }}<span class="block text-xs font-normal text-navy-600">{{ $job['queue'] }}</span></td>
                                <td class="crm-td max-w-md break-words text-xs text-red-700">{{ $job['error'] }}</td>
                                <td class="crm-td whitespace-nowrap">{{ $job['failed_at']->format('d.m.Y H:i') }}</td>
                                <td class="crm-td">
                                    <div class="flex justify-end gap-1">
                                        <button type="button" wire:click="retryFailed('{{ $job['uuid'] }}')" class="crm-btn-ghost">{{ __('crm.system.retry') }}</button>
                                        <button type="button" wire:click="forgetFailed('{{ $job['uuid'] }}')" wire:confirm="{{ __('crm.system.delete_confirm') }}" class="crm-btn-ghost text-red-600">{{ __('crm.system.delete') }}</button>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
</div>
