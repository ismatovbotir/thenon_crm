<?php

use App\Models\Location;
use App\Models\LocationCutoff;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component
{
    #[Locked]
    public int $locationId;

    /** @var array<int, array{is_open: bool, time: string}> */
    public array $rows = [];

    public function mount(int $locationId): void
    {
        $this->locationId = $locationId;

        $existing = LocationCutoff::query()->where('location_id', $locationId)->get()->keyBy('weekday');

        foreach (range(1, 7) as $weekday) {
            $rule = $existing->get($weekday);

            $this->rows[$weekday] = [
                'is_open' => $rule?->is_open ?? true,
                'time' => $rule?->cutoffTimeLabel() ?? '',
            ];
        }
    }

    public function location(): Location
    {
        return Location::query()->findOrFail($this->locationId);
    }

    public function closeForm(): void
    {
        $this->dispatch('schedule-closed');
    }

    public function save(): void
    {
        $this->validate(
            ['rows.*.time' => ['nullable', 'date_format:H:i']],
            attributes: ['rows.*.time' => __('crm.schedule.cutoff')],
        );

        foreach ($this->rows as $weekday => $row) {
            $time = $row['is_open'] && $row['time'] !== '' ? $row['time'] : null;

            if ($row['is_open'] && $time === null) {
                LocationCutoff::query()->where('location_id', $this->locationId)->where('weekday', $weekday)->delete();

                continue;
            }

            LocationCutoff::query()->updateOrCreate(
                ['location_id' => $this->locationId, 'weekday' => $weekday],
                ['is_open' => (bool) $row['is_open'], 'cutoff_time' => $time],
            );
        }

        $this->dispatch('schedule-closed');
    }
};
?>

<div>
    <x-crm.modal :title="__('crm.schedule.title', ['location' => $this->location()->name])" max-width="max-w-lg">
        <form wire:submit="save" class="space-y-4">
            <p class="text-sm text-navy-600">{{ __('crm.schedule.hint') }}</p>

            <div class="divide-y divide-navy-100 rounded-xl ring-1 ring-navy-100">
                @foreach ($rows as $weekday => $row)
                    <div wire:key="day-{{ $weekday }}" class="flex flex-wrap items-center gap-3 px-4 py-3">
                        <p class="w-28 font-medium text-navy-800">{{ __('crm.weekdays.'.$weekday) }}</p>
                        <label class="flex items-center gap-2 text-sm text-navy-700">
                            <input type="checkbox" wire:model.live="rows.{{ $weekday }}.is_open" class="size-4 rounded border-navy-100 text-navy-800 focus:ring-gold-400">
                            {{ __('crm.schedule.open') }}
                        </label>
                        @if ($row['is_open'])
                            <div class="ml-auto">
                                <input type="time" wire:model="rows.{{ $weekday }}.time" aria-label="{{ __('crm.schedule.cutoff') }}" class="crm-input w-32 py-1.5">
                                @error('rows.'.$weekday.'.time')
                                    <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                                @enderror
                            </div>
                        @else
                            <span class="ml-auto text-sm text-red-600">{{ __('crm.schedule.closed') }}</span>
                        @endif
                    </div>
                @endforeach
            </div>

            <div class="flex justify-end gap-2 pt-2">
                <button type="button" wire:click="closeForm" class="crm-btn-secondary">{{ __('crm.common.cancel') }}</button>
                <button type="submit" class="crm-btn-primary">{{ __('crm.common.save') }}</button>
            </div>
        </form>
    </x-crm.modal>
</div>
