<?php
if (!defined('ABSPATH')) {
    exit;
}

class WSS_WCB_YC_ICS_Builder
{
    public static function build(array $event): string
    {
        $now = gmdate('Ymd\THis\Z');
        $tzid = self::normalize_tzid((string) ($event['timezone'] ?? 'Europe/Moscow'));

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//WSS//WooCommerce Bookings Yandex Calendar//RU',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'X-WR-TIMEZONE:' . $tzid,
            'BEGIN:VEVENT',
            'UID:' . self::escape_text($event['uid']),
            'DTSTAMP:' . $now,
            'CREATED:' . ($event['created_utc'] ?? $now),
            'LAST-MODIFIED:' . $now,
            'DTSTART;TZID=' . $tzid . ':' . self::format_local((int) $event['start_ts'], $tzid),
            'DTEND;TZID=' . $tzid . ':' . self::format_local((int) $event['end_ts'], $tzid),
            'SUMMARY:' . self::escape_text($event['summary']),
            'DESCRIPTION:' . self::escape_text($event['description']),
            'STATUS:CONFIRMED',
            'TRANSP:OPAQUE',
        ];

        if (!empty($event['cancelled'])) {
            $lines[] = 'CATEGORIES:' . self::escape_text('Отменено');
        }

        $lines[] = 'END:VEVENT';
        $lines[] = 'END:VCALENDAR';

        $folded = array_map([self::class, 'fold_line'], $lines);
        return implode("\r\n", $folded) . "\r\n";
    }

    private static function format_local(int $timestamp, string $tzid): string
    {
        if ($timestamp <= 0) {
            $timestamp = time();
        }

        try {
            $timezone = new DateTimeZone($tzid);
        } catch (Throwable $e) {
            $timezone = new DateTimeZone('Europe/Moscow');
        }

        $dt = new DateTimeImmutable('@' . $timestamp);
        return $dt->setTimezone($timezone)->format('Ymd\THis');
    }

    private static function normalize_tzid(string $tzid): string
    {
        $tzid = trim($tzid);
        if ($tzid === '') {
            return 'Europe/Moscow';
        }

        try {
            new DateTimeZone($tzid);
            return preg_replace('/[^A-Za-z0-9_\/\-+]/', '', $tzid) ?: 'Europe/Moscow';
        } catch (Throwable $e) {
            return 'Europe/Moscow';
        }
    }

    private static function escape_text(string $value): string
    {
        $value = wp_strip_all_tags($value);
        $value = str_replace("\r\n", "\n", $value);
        $value = str_replace("\r", "\n", $value);
        $value = str_replace('\\', '\\\\', $value);
        $value = str_replace(';', '\;', $value);
        $value = str_replace(',', '\,', $value);
        $value = str_replace("\n", '\\n', $value);
        return $value;
    }

    private static function fold_line(string $line): string
    {
        if (strlen($line) <= 72) {
            return $line;
        }

        $chunks = [];
        $current = $line;

        while (strlen($current) > 72) {
            if (function_exists('mb_substr') && function_exists('mb_strlen')) {
                $part = mb_substr($current, 0, 72, 'UTF-8');
                $chunks[] = $part;
                $current = mb_substr($current, mb_strlen($part, 'UTF-8'), null, 'UTF-8');
            } else {
                $part = substr($current, 0, 72);
                $chunks[] = $part;
                $current = substr($current, 72);
            }
        }

        if ($current !== '') {
            $chunks[] = $current;
        }

        return implode("\r\n ", $chunks);
    }
}
