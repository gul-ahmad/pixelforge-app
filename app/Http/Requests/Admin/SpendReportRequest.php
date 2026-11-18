<?php

namespace App\Http\Requests\Admin;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Date range for the spend report, in UTC days (Pixelforge bills per UTC day).
 * Defaults to the last 30 days, like GET /v1/usage. At most 92 days, Pixelforge's limit.
 */
class SpendReportRequest extends FormRequest
{
    public const int MAX_DAYS = 92;

    public function authorize(): bool
    {
        return $this->user()?->can('view-admin-report') ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start_date',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if ($this->startDate()->diffInDays($this->endDate()) + 1 > self::MAX_DAYS) {
                        $fail('The report can cover at most '.self::MAX_DAYS.' days (Pixelforge\'s usage limit).');
                    }
                },
            ],
        ];
    }

    public function endDate(): CarbonImmutable
    {
        $end = $this->input('end_date');

        return is_string($end) && $end !== '' ? CarbonImmutable::parse($end, 'UTC') : CarbonImmutable::now('UTC')->startOfDay();
    }

    public function startDate(): CarbonImmutable
    {
        $start = $this->input('start_date');

        return is_string($start) && $start !== '' ? CarbonImmutable::parse($start, 'UTC') : $this->endDate()->subDays(29);
    }
}
