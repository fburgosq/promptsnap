<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Validator;

class AdminReportRequest extends FormRequest
{
    public const int MAX_RANGE_DAYS = 92;

    public function authorize(): bool
    {
        return $this->user() !== null && (bool) $this->user()->is_admin;
    }

    protected function prepareForValidation(): void
    {
        $today = Carbon::now('UTC')->toDateString();
        $defaultStart = Carbon::now('UTC')->subDays(29)->toDateString();

        $this->merge([
            'start_date' => is_string($this->input('start_date')) && $this->input('start_date') !== ''
                ? $this->input('start_date')
                : $defaultStart,
            'end_date' => is_string($this->input('end_date')) && $this->input('end_date') !== ''
                ? $this->input('end_date')
                : $today,
            'view' => in_array($this->input('view'), ['by_day', 'by_user'], true)
                ? $this->input('view')
                : 'by_day',
        ]);
    }

    /**
     * @return array<string, array<int, ValidationRule|string>>
     */
    public function rules(): array
    {
        return [
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'view' => ['required', 'string', 'in:by_day,by_user'],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->hasAny(['start_date', 'end_date'])) {
                    return;
                }

                $start = Carbon::createFromFormat('Y-m-d', (string) $this->input('start_date'), 'UTC')->startOfDay();
                $end = Carbon::createFromFormat('Y-m-d', (string) $this->input('end_date'), 'UTC')->startOfDay();

                $daysInclusive = (int) $start->diffInDays($end) + 1;

                if ($daysInclusive > self::MAX_RANGE_DAYS) {
                    $validator->errors()->add(
                        'end_date',
                        'The date range cannot exceed '.self::MAX_RANGE_DAYS.' days.',
                    );
                }
            },
        ];
    }
}
