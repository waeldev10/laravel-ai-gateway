<?php

namespace App\Support;

use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Single authoritative source for user-facing Arabic date/time strings.
 *
 * Server-side only: Blade calls these with real `created_at` values, so no
 * timestamp is ever generated in JavaScript and no formatting logic is
 * duplicated across views. Eastern Arabic digits (٠-٩) match the existing
 * Arabic UI; month names come from Carbon's `ar` translations, pinned
 * explicitly per instance so output never depends on global locale state.
 */
final class ArabicDateTime
{
    /**
     * User-friendly timestamp for a single chat message.
     *
     * Recent messages are relative (الآن / منذ دقيقتين / منذ ساعة),
     * older ones fall back to day + time (أمس، ١٠:١٥ ص) and then full dates.
     */
    public static function forMessage(CarbonInterface $at, ?CarbonInterface $now = null): string
    {
        $now = $now ? Carbon::instance($now) : Carbon::now();
        $date = Carbon::instance($at)->locale('ar');

        if ($date->greaterThan($now)) {
            return 'الآن';
        }

        $seconds = (int) abs($now->diffInSeconds($date));

        if ($seconds < 60) {
            return 'الآن';
        }

        if ($seconds < 3600) {
            return 'منذ '.self::pluralize((int) ($seconds / 60), 'دقيقة', 'دقيقتين', 'دقائق', 'دقيقة');
        }

        if ($date->isSameDay($now)) {
            return 'منذ '.self::pluralize((int) ($seconds / 3600), 'ساعة', 'ساعتين', 'ساعات', 'ساعة');
        }

        if ($date->toDateString() === $now->copy()->subDay()->toDateString()) {
            return 'أمس، '.self::time($date);
        }

        if ((int) $date->format('Y') === (int) $now->format('Y')) {
            return self::digits($date->translatedFormat('j F')).'، '.self::time($date);
        }

        return self::digits($date->translatedFormat('j F Y')).'، '.self::time($date);
    }

    /**
     * Full date + time for a Search result row, always visible.
     *
     * Unlike message timestamps this never collapses to a relative form so
     * every result carries its complete timestamp, e.g. ١٣ سبتمبر ٢٠٢٦، ٤:٣٠ م.
     * Callers pass the conversation's `created_at`: it is stable (pin and
     * rename only touch `pinned_at`/`title` plus `updated_at`) and matches
     * the existing newest-first ordering.
     */
    public static function forSearch(CarbonInterface $at): string
    {
        $date = Carbon::instance($at)->locale('ar');

        return self::digits($date->translatedFormat('j F Y')).'، '.self::time($date);
    }

    /**
     * Time only for the Sidebar hover reveal, e.g. ٤:٣٠ م.
     *
     * Rendered server-side like every other timestamp; showing/hiding it is
     * pure CSS (`group-hover`), so hovering never triggers a request.
     */
    public static function timeOnly(CarbonInterface $at): string
    {
        return self::time(Carbon::instance($at)->locale('ar'));
    }

    /**
     * Arabic pluralization for time units: 1 → singular, 2 → dual,
     * 3-10 → plural, 11+ → singular accusative form.
     */
    private static function pluralize(int $n, string $one, string $two, string $few, string $many): string
    {
        if ($n === 1) {
            return $one;
        }

        if ($n === 2) {
            return $two;
        }

        if ($n >= 3 && $n <= 10) {
            return self::digits($n).' '.$few;
        }

        return self::digits($n).' '.$many;
    }

    /**
     * 12-hour clock with ص/م, e.g. ٤:٣٠ م.
     */
    private static function time(Carbon $date): string
    {
        return self::digits($date->translatedFormat('g:i a'));
    }

    public static function digits(string|int $value): string
    {
        return strtr((string) $value, [
            '0' => '٠', '1' => '١', '2' => '٢', '3' => '٣', '4' => '٤',
            '5' => '٥', '6' => '٦', '7' => '٧', '8' => '٨', '9' => '٩',
        ]);
    }
}
