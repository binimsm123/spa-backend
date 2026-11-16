<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Business;
use App\Models\BusinessUser;
use App\Models\Service;
use App\Models\StaffLeave;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class StaffAvailabilityService
{
    /** @return array{staff: Collection<int, BusinessUser>, bookings: Collection<int, Booking>} */
    public function scheduleFor(Business $business, Carbon $date, bool $lock = false, ?string $exceptBookingId = null): array
    {
        return $this->loadSchedule($business, $date, $date, $lock, $exceptBookingId);
    }

    /** @return array{staff: Collection<int, BusinessUser>, bookings: Collection<int, Booking>} */
    public function scheduleForRange(Business $business, Carbon $startsOn, Carbon $endsOn): array
    {
        return $this->loadSchedule($business, $startsOn, $endsOn);
    }

    /** @return array{staff: Collection<int, BusinessUser>, bookings: Collection<int, Booking>} */
    private function loadSchedule(Business $business, Carbon $startsOn, Carbon $endsOn, bool $lock = false, ?string $exceptBookingId = null): array
    {
        $staff = BusinessUser::query()
            ->where('business_id', $business->id)->where('is_active', true)
            ->whereHas('user', fn (Builder $query) => $query->where('is_active', true))
            ->when($lock, fn (Builder $query) => $query->lockForUpdate())
            ->with(['user', 'business',
                'services' => fn ($query) => $query->when($lock, fn ($query) => $query->lockForUpdate()),
                'hours' => fn ($query) => $query->when($lock, fn ($query) => $query->lockForUpdate()),
                'leaves' => fn ($query) => $query
                    ->where('starts_on', '<=', $endsOn->copy()->addDays(2)->toDateString())
                    ->where('ends_on', '>=', $startsOn->copy()->subDays(2)->toDateString())
                    ->when($lock, fn ($query) => $query->lockForUpdate())])
            ->orderBy('user_id')->get();
        $staffIds = $staff->pluck('user_id');
        if ($lock) {
            $activeIds = User::query()->whereIn('id', $staffIds)->where('is_active', true)->orderBy('id')->lockForUpdate()->pluck('id');
            $staff = $staff->whereIn('user_id', $activeIds)->values();
        }

        $bookings = Booking::query()
            ->where(fn (Builder $query) => $query->where('business_id', $business->id)
                ->orWhereIn('assigned_staff_user_id', $staffIds)
                ->orWhereHas('assignedStaffMembers', fn (Builder $query) => $query->whereIn('users.id', $staffIds)))
            ->when($exceptBookingId !== null, fn (Builder $query) => $query->whereKeyNot($exceptBookingId))
            ->whereBetween('appointment_date', [$startsOn->copy()->subDays(2)->toDateString(), $endsOn->copy()->addDays(2)->toDateString()])
            ->whereIn('status', ['awaiting_payment', 'confirmed', 'in_progress', 'completed'])
            ->with([
                'assignedStaffMembers' => fn ($query) => $query->when($lock, fn ($query) => $query->lockForUpdate()),
                'items' => fn ($query) => $query->when($lock, fn ($query) => $query->lockForUpdate()),
            ])
            ->when($lock, fn (Builder $query) => $query->lockForUpdate())->get();

        return ['staff' => $staff, 'bookings' => $bookings];
    }

    /**
     * Find a feasible staffing plan without persisting assignments. Unassigned bookings reserve
     * capacity among staff with the right expertise; nonoverlapping bookings can reuse staff.
     *
     * @param  array{staff: Collection<int, BusinessUser>, bookings: Collection<int, Booking>}  $schedule
     * @param  array<int, string>|null  $selectedStaffIds
     * @return Collection<int, BusinessUser>
     */
    public function suggestionsFor(Service $service, array $schedule, Carbon $startsAt, Carbon $endsAt, int $requiredCount, ?array $selectedStaffIds = null): Collection
    {
        $fixed = [];
        $pending = [];
        foreach ($schedule['bookings'] as $booking) {
            $start = Carbon::parse($booking->getRawOriginal('starts_at'), $booking->timezone);
            $end = Carbon::parse($booking->getRawOriginal('ends_at'), $booking->timezone);
            $assignedIds = $booking->assignedStaffMembers->pluck('id');
            if ($booking->assigned_staff_user_id !== null) {
                $assignedIds->push($booking->assigned_staff_user_id);
            }
            if ($assignedIds->isNotEmpty()) {
                foreach ($assignedIds->unique() as $staffId) {
                    $fixed[] = ['staff_id' => $staffId, 'starts_at' => $start, 'ends_at' => $end];
                }
            } elseif ($booking->items->isEmpty()) {
                $fixed[] = ['staff_id' => null, 'starts_at' => $start, 'ends_at' => $end];
            } elseif ($booking->business_id === $service->business_id) {
                $pending[] = [
                    'id' => $booking->id, 'service_id' => $booking->items->first()->service_id,
                    'required' => $this->requiredStaffForBooking($booking), 'starts_at' => $start, 'ends_at' => $end,
                ];
            }
        }

        $jobs = [['id' => 'requested', 'service_id' => $service->id, 'required' => $requiredCount, 'starts_at' => $startsAt, 'ends_at' => $endsAt]];
        $rangeStart = $startsAt->copy();
        $rangeEnd = $endsAt->copy();
        do {
            $added = false;
            foreach ($pending as $index => $job) {
                if ($job['starts_at']->lt($rangeEnd) && $job['ends_at']->gt($rangeStart)) {
                    $jobs[] = $job;
                    $rangeStart = $rangeStart->min($job['starts_at']);
                    $rangeEnd = $rangeEnd->max($job['ends_at']);
                    unset($pending[$index]);
                    $added = true;
                }
            }
        } while ($added);

        foreach ($jobs as &$job) {
            $job['candidates'] = $schedule['staff']->filter(function (BusinessUser $membership) use ($job, $fixed, $selectedStaffIds): bool {
                if ($job['id'] === 'requested' && $selectedStaffIds !== null && ! in_array($membership->user_id, $selectedStaffIds, true)) {
                    return false;
                }

                return $membership->services->contains('id', $job['service_id'])
                    && $this->isWorkingDuring($membership, $job['starts_at'], $job['ends_at'])
                    && ! collect($fixed)->contains(fn (array $interval): bool => ($interval['staff_id'] === null || $interval['staff_id'] === $membership->user_id)
                        && $job['starts_at']->lt($interval['ends_at']) && $job['ends_at']->gt($interval['starts_at']));
            })->pluck('user_id')->all();
            if (count($job['candidates']) < $job['required']) {
                return collect();
            }
        }
        unset($job);
        usort($jobs, fn (array $first, array $second): int => (count($first['candidates']) - $first['required']) <=> (count($second['candidates']) - $second['required']));
        $plan = $this->allocate($jobs);
        if ($plan === null) {
            return collect();
        }

        return $schedule['staff']->whereIn('user_id', $plan['requested'])->values();
    }

    public function requiredStaffForBooking(Booking $booking): int
    {
        return max(1, (int) $booking->items->first()?->required_staff_count_snapshot);
    }

    private function isWorkingDuring(BusinessUser $membership, Carbon $startsAt, Carbon $endsAt): bool
    {
        $timezone = $membership->business->timezone;
        $start = $startsAt->copy()->setTimezone($timezone);
        $end = $endsAt->copy()->setTimezone($timezone);
        $hours = $membership->hours->firstWhere('weekday', $start->dayOfWeek);
        if ($start->toDateString() !== $end->toDateString() || $hours === null || $hours->is_closed || $hours->opens_at === null || $hours->closes_at === null) {
            return false;
        }
        if ($start->lt($start->copy()->setTimeFromTimeString($hours->getRawOriginal('opens_at')))
            || $end->gt($start->copy()->setTimeFromTimeString($hours->getRawOriginal('closes_at')))) {
            return false;
        }
        foreach ($hours->breaks ?? [] as $break) {
            if ($start->lt($start->copy()->setTimeFromTimeString($break['ends_at']))
                && $end->gt($start->copy()->setTimeFromTimeString($break['starts_at']))) {
                return false;
            }
        }

        return ! $membership->leaves->contains(function (StaffLeave $leave) use ($start, $end): bool {
            if ($leave->starts_on->toDateString() > $start->toDateString() || $leave->ends_on->toDateString() < $start->toDateString()) {
                return false;
            }
            if ($leave->starts_at === null || $leave->ends_at === null) {
                return true;
            }

            return $start->lt($start->copy()->setTimeFromTimeString($leave->getRawOriginal('ends_at')))
                && $end->gt($start->copy()->setTimeFromTimeString($leave->getRawOriginal('starts_at')));
        });
    }

    /**
     * @param  array<int, array{id: string, service_id: string, required: int, starts_at: Carbon, ends_at: Carbon, candidates: array<int, string>}>  $jobs
     * @param  array<string, array<int, string>>  $plan
     * @param  array<string, array<int, array{starts_at: Carbon, ends_at: Carbon}>>  $busy
     * @return array<string, array<int, string>>|null
     */
    private function allocate(array $jobs, array $plan = [], array $busy = []): ?array
    {
        if ($jobs === []) {
            return $plan;
        }
        $job = array_shift($jobs);
        $free = array_values(array_filter($job['candidates'], fn (string $staffId): bool => ! collect($busy[$staffId] ?? [])
            ->contains(fn (array $interval): bool => $job['starts_at']->lt($interval['ends_at']) && $job['ends_at']->gt($interval['starts_at']))));
        foreach ($this->combinations($free, $job['required']) as $staffIds) {
            $nextBusy = $busy;
            foreach ($staffIds as $staffId) {
                $nextBusy[$staffId][] = ['starts_at' => $job['starts_at'], 'ends_at' => $job['ends_at']];
            }
            $result = $this->allocate($jobs, $plan + [$job['id'] => $staffIds], $nextBusy);
            if ($result !== null) {
                return $result;
            }
        }

        return null;
    }

    /**
     * @param  array<int, string>  $staffIds
     * @return \Generator<int, array<int, string>>
     */
    private function combinations(array $staffIds, int $count): \Generator
    {
        if ($count === 0) {
            yield [];

            return;
        }
        while (count($staffIds) >= $count) {
            $first = array_shift($staffIds);
            foreach ($this->combinations($staffIds, $count - 1) as $rest) {
                yield [$first, ...$rest];
            }
        }
    }
}
