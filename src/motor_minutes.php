<?php
declare(strict_types=1);

function motor_minutes_normalize(mixed $value): ?int
{
    if ($value === null || trim((string)$value) === '') {
        return null;
    }
    if (filter_var($value, FILTER_VALIDATE_INT) === false || (int)$value < 0) {
        throw new RuntimeException('Motorminuten müssen eine ganze Zahl ab 0 sein.');
    }
    return (int)$value;
}

function motor_minutes_for_entry(string $entryType, mixed $value): ?int
{
    return $entryType === 'glider_flight' ? motor_minutes_normalize($value) : null;
}

function motor_minutes_export_times(mixed $value): array
{
    $minutes = motor_minutes_normalize($value);
    if ($minutes === null) {
        return ['motor_start' => '', 'motor_end' => ''];
    }
    return [
        'motor_start' => '00:00',
        'motor_end' => sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60),
    ];
}
