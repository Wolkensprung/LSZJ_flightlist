<?php
function json_response($data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function minutes_between(?string $date, ?string $start, ?string $end): ?int {
    if (!$date || !$start || !$end || $start === '00:00:00' || $end === '00:00:00') return null;
    $a = strtotime($date . ' ' . $start);
    $b = strtotime($date . ' ' . $end);
    if ($a === false || $b === false || $b < $a) return null;
    return (int) round(($b - $a) / 60);
}
function dt_string(?string $date, ?string $time): ?string {
    if (!$date || !$time || $time === '00:00:00') return null;
    return date('Y-m-d H:i:s', strtotime($date . ' ' . $time));
}
function vf_dt(?string $mysqlDateTime): string {
    if (!$mysqlDateTime) return '';
    return date('d.m.Y H:i', strtotime($mysqlDateTime));
}
function normalize_airfield(?string $loc): string {
    return strtoupper(trim((string)$loc));
}
