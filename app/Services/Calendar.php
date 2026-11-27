<?php

namespace App\Services;

use Carbon\CarbonImmutable;

/** Lead ke local time ka hisaab: subah ka window, Mon-Fri, US chhuttiyan */
class Calendar
{
    public static function tz(string $zone): string
    {
        return config('sender.zones')[$zone] ?? 'America/New_York';
    }

    public static function now(string $zone): CarbonImmutable
    {
        return CarbonImmutable::now(self::tz($zone));
    }

    /** US federal holidays (observed) + Thanksgiving ke baad ka Friday */
    public static function holidays(int $y): array
    {
        $nth = fn ($n, $dow, $m) => CarbonImmutable::create($y, $m, 1)->nthOfMonth($n, $dow)->toDateString();
        $last = fn ($dow, $m) => CarbonImmutable::create($y, $m, 1)->lastOfMonth($dow)->toDateString();
        $obs = function ($m, $d) use ($y) {
            $t = CarbonImmutable::create($y, $m, $d);
            if ($t->isSaturday()) {
                $t = $t->subDay();
            } elseif ($t->isSunday()) {
                $t = $t->addDay();
            }

            return $t->toDateString();
        };
        $thanks = $nth(4, CarbonImmutable::THURSDAY, 11);

        return [
            $obs(1, 1), $nth(3, CarbonImmutable::MONDAY, 1), $nth(3, CarbonImmutable::MONDAY, 2), $last(CarbonImmutable::MONDAY, 5),
            $obs(6, 19), $obs(7, 4), $nth(1, CarbonImmutable::MONDAY, 9), $nth(2, CarbonImmutable::MONDAY, 10), $obs(11, 11),
            $thanks, CarbonImmutable::parse($thanks)->addDay()->toDateString(), $obs(12, 24) === $obs(12, 25) ? null : $obs(12, 24), $obs(12, 25),
        ];
    }

    public static function isWorkday(CarbonImmutable $d): bool
    {
        return ! $d->isWeekend() && ! in_array($d->toDateString(), array_filter(self::holidays($d->year)), true);
    }

    /** Abhi us zone mein bhejne ka time hai? */
    public static function windowOpen(string $zone, ?CarbonImmutable $at = null): bool
    {
        $n = ($at ?? CarbonImmutable::now())->setTimezone(self::tz($zone));
        if (! self::isWorkday($n)) {
            return false;
        }
        $hm = $n->format('H:i');

        return $hm >= config('sender.window_from') && $hm < config('sender.window_to');
    }

    public static function windowMinutes(): int
    {
        [$a, $b] = [explode(':', config('sender.window_from')), explode(':', config('sender.window_to'))];

        return max(30, ($b[0] * 60 + $b[1]) - ($a[0] * 60 + $a[1]));
    }

    /** $days kaam ke din baad, us din ka window shuru (lead ke time mein) - UTC mein lautata hai */
    public static function afterWorkdays(string $zone, int $days, ?CarbonImmutable $from = null): CarbonImmutable
    {
        $d = ($from ?? CarbonImmutable::now())->setTimezone(self::tz($zone))->startOfDay();
        $n = 0;
        while ($n < $days) {
            $d = $d->addDay();
            if (self::isWorkday($d)) {
                $n++;
            }
        }
        [$h, $m] = explode(':', config('sender.window_from'));

        return $d->setTime((int) $h, (int) $m)->setTimezone('UTC');
    }

    /** Nayi ID ka ramp: pehle kaam ke din 5, phir har kaam ke din +3, max cap tak */
    public static function rampCap(?CarbonImmutable $start, int $cap, ?CarbonImmutable $today = null): int
    {
        if (! $start) {
            return min($cap, config('sender.ramp_start_cap'));
        }
        $today = ($today ?? CarbonImmutable::now('America/New_York'))->toDateString();
        $d = CarbonImmutable::parse($start->toDateString(), 'America/New_York');
        $n = 0;
        while ($d->toDateString() < $today && $n < 50) {
            $d = $d->addDay();
            if (self::isWorkday($d)) {
                $n++;
            }
        }

        return min($cap, config('sender.ramp_start_cap') + config('sender.ramp_step') * $n);
    }
}
