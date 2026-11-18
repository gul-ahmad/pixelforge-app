<?php

namespace App\Actions\Admin;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\User;
use App\Support\Pricing;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The operators' spend report: what users were charged, what Pixelforge charged us, and the
 * profit, per day and per user, reconciled day by day against GET /v1/usage.
 *
 * Rules (they decide whether our numbers can match Pixelforge's):
 *  - A generation belongs to the UTC day it COMPLETED: that's the day Pixelforge bills it.
 *  - Only succeeded generations cost anything; failed and rejected ones are never billed.
 *  - All sums are integers (micro-/nano-USD); profit is computed in nano-USD.
 */
class BuildSpendReport
{
    public function __construct(private readonly FetchProviderUsage $fetchUsage) {}

    /**
     * @return array<string, mixed> ready to send to the admin page
     */
    public function handle(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $start = $start->utc()->startOfDay();
        $end = $end->utc()->startOfDay();

        $rows = $this->ourCostsByDayAndUser($start, $end);
        $users = User::query()
            ->whereIn('id', $rows->map(fn (SpendRow $row) => $row->userId)->filter()->unique()->all())
            ->get()
            ->keyBy('id');
        $pixelforge = $this->fetchUsage->handle($start, $end);

        $ours = $this->sum($rows);
        $theirsTotal = $pixelforge === null ? null : array_sum(array_column($pixelforge, 'cost_nano_usd'));

        return [
            'range' => ['start' => $start->toDateString(), 'end' => $end->toDateString()],
            'pixelforge_available' => $pixelforge !== null,
            'totals' => [
                ...$this->money($ours),
                'pixelforge_cost' => $theirsTotal === null ? null : Pricing::formatNanoUsdWithSymbol($theirsTotal),
                'matches' => $theirsTotal === null ? null : $theirsTotal === $ours['cost_nano_usd'],
            ],
            'days' => $this->days($rows, $users, $pixelforge),
            'users' => $this->perUser($rows, $users),
        ];
    }

    /**
     * One row per (completion day, user), summed by the database.
     *
     * @return Collection<int, SpendRow>
     */
    private function ourCostsByDayAndUser(CarbonImmutable $start, CarbonImmutable $end): Collection
    {
        return Generation::query()
            ->where('status', GenerationStatus::Succeeded)
            ->where('completed_at', '>=', $start)
            ->where('completed_at', '<', $end->addDay())
            // DATE() exists in both MySQL and SQLite; MySQL's session runs in UTC (config/database.php).
            ->selectRaw('DATE(completed_at) as day, user_id, COUNT(*) as generations, SUM(charged_micro_usd) as charged, SUM(provider_cost_nano_usd) as cost')
            ->groupByRaw('DATE(completed_at), user_id')
            ->toBase()
            ->get()
            ->map(fn (object $row) => new SpendRow(
                day: (string) $row->day,
                userId: $row->user_id === null ? null : (int) $row->user_id,
                generations: (int) $row->generations,
                chargedMicroUsd: (int) $row->charged,
                costNanoUsd: (int) $row->cost,
            ))
            ->values();
    }

    /**
     * Every day where we or Pixelforge recorded something, newest first, with the check.
     *
     * @param  Collection<int, SpendRow>  $rows
     * @param  Collection<array-key, User>  $users
     * @param  array<string, array{generations: int, cost_nano_usd: int}>|null  $pixelforge
     * @return list<array<string, mixed>>
     */
    private function days(Collection $rows, Collection $users, ?array $pixelforge): array
    {
        $dates = $rows->map(fn (SpendRow $row) => $row->day)->all();

        foreach ($pixelforge ?? [] as $date => $day) {
            if ($day['generations'] > 0) {
                $dates[] = $date;
            }
        }

        $dates = array_values(array_unique($dates));
        rsort($dates);

        $report = [];

        foreach ($dates as $date) {
            $dayRows = $rows->filter(fn (SpendRow $row) => $row->day === $date);
            $ours = $this->sum($dayRows);
            $theirs = $pixelforge === null ? null : ($pixelforge[$date] ?? ['generations' => 0, 'cost_nano_usd' => 0]);

            $report[] = [
                'date' => $date,
                ...$this->money($ours),
                'pixelforge' => $theirs === null ? null : [
                    'generations' => $theirs['generations'],
                    'cost' => Pricing::formatNanoUsdWithSymbol($theirs['cost_nano_usd']),
                ],
                // Match = same number of billed generations AND exactly the same cost.
                'matches' => $theirs === null ? null
                    : $theirs['cost_nano_usd'] === $ours['cost_nano_usd'] && $theirs['generations'] === $ours['generations'],
                'difference' => $theirs === null ? null
                    : Pricing::formatNanoUsdWithSymbol($ours['cost_nano_usd'] - $theirs['cost_nano_usd']),
                'users' => $this->perUser($dayRows, $users),
            ];
        }

        return $report;
    }

    /**
     * @param  Collection<int, SpendRow>  $rows
     * @param  Collection<array-key, User>  $users
     * @return list<array<string, mixed>>
     */
    private function perUser(Collection $rows, Collection $users): array
    {
        $perUser = $rows->groupBy(fn (SpendRow $row) => (string) $row->userId)
            ->map(fn (Collection $userRows) => [
                ...$this->person($users->get((int) $userRows->first()?->userId)),
                ...$this->money($this->sum($userRows)),
            ])
            ->sortByDesc('charged_nano_usd')
            ->values()
            ->all();

        return array_values($perUser);
    }

    /**
     * @param  Collection<array-key, SpendRow>  $rows
     * @return array{generations: int, charged_micro_usd: int, cost_nano_usd: int}
     */
    private function sum(Collection $rows): array
    {
        return [
            'generations' => (int) $rows->sum(fn (SpendRow $row) => $row->generations),
            'charged_micro_usd' => (int) $rows->sum(fn (SpendRow $row) => $row->chargedMicroUsd),
            'cost_nano_usd' => (int) $rows->sum(fn (SpendRow $row) => $row->costNanoUsd),
        ];
    }

    /**
     * Charged, cost and profit, all shown with 9 decimals so the columns line up.
     *
     * @param  array{generations: int, charged_micro_usd: int, cost_nano_usd: int}  $totals
     * @return array<string, mixed>
     */
    private function money(array $totals): array
    {
        $chargedNano = Pricing::microToNano($totals['charged_micro_usd']);

        return [
            'generations' => $totals['generations'],
            'charged' => Pricing::formatNanoUsdWithSymbol($chargedNano),
            'provider_cost' => Pricing::formatNanoUsdWithSymbol($totals['cost_nano_usd']),
            'profit' => Pricing::formatNanoUsdWithSymbol($chargedNano - $totals['cost_nano_usd']),
            'charged_nano_usd' => $chargedNano,   // used for sorting
        ];
    }

    /**
     * @return array{user_id: int|null, name: string, email: string|null}
     */
    private function person(?User $user): array
    {
        return [
            'user_id' => $user?->id,
            'name' => $user->name ?? 'Deleted user',
            'email' => $user?->email,
        ];
    }
}
