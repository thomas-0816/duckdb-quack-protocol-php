<?php

namespace Quack\Protocol\Types;

/**
 * Formats DuckDB temporal values without relying on PHP's DateTime classes.
 *
 * The algorithms work directly on the protocol's integer representations so
 * that dates and timestamps remain valid across the full DuckDB range,
 * including negative years.
 */
final class TemporalFormatter
{
    private const NANOS_PER_SECOND = 1_000_000_000;
    private const NANOS_PER_MICRO = 1_000;
    private const SECONDS_PER_DAY = 86_400;

    public static function formatDate(int $days): string
    {
        if ($days === 2_147_483_647) {
            return 'infinity';
        }
        if ($days === -2_147_483_648) {
            return '-infinity';
        }

        return self::formatCivilDate($days);
    }

    public static function formatTime(int $value, TimeUnit $unit): string
    {
        $nanos = BigInt::fromInt($value);
        if ($unit === TimeUnit::Micros) {
            $nanos = $nanos->mul(BigInt::fromInt(self::NANOS_PER_MICRO));
        }

        return self::formatTimeOfDay($nanos);
    }

    public static function formatTimeTz(int $bits): string
    {
        $offsetMask = (1 << 24) - 1;
        $maxOffset = 16 * 60 * 60 - 1;
        $offset = $maxOffset - ($bits & $offsetMask);
        $micros = $bits >> 24;
        $nanos = BigInt::fromInt($micros)->mul(BigInt::fromInt(self::NANOS_PER_MICRO));
        $sign = $offset < 0 ? '-' : '+';
        $absoluteOffset = abs($offset);
        $offsetText = sprintf(
            '%s%02d:%02d',
            $sign,
            intdiv($absoluteOffset, 3_600),
            intdiv($absoluteOffset % 3_600, 60),
        );
        $seconds = $absoluteOffset % 60;
        if ($seconds !== 0) {
            $offsetText .= sprintf(':%02d', $seconds);
        }

        return self::formatTimeOfDay($nanos) . $offsetText;
    }

    /**
     * Formats a timestamp with a space separator and a trimmed fraction, the
     * way DuckDB renders TIMESTAMP values.
     */
    public static function formatTimestamp(
        int $value,
        TimestampUnit $unit,
        bool $timezoneUtc = false,
    ): string {
        if ($value === PHP_INT_MAX) {
            return 'infinity';
        }
        if ($value === PHP_INT_MIN) {
            return '-infinity';
        }

        $nanos = self::toNanos($value, $unit);
        [$seconds, $fraction] = self::euclidDivMod(
            $nanos,
            BigInt::fromInt(self::NANOS_PER_SECOND),
        );
        [$days, $secondOfDay] = self::euclidDivMod(
            $seconds,
            BigInt::fromInt(self::SECONDS_PER_DAY),
        );
        $secondsOfDay = $secondOfDay->toInt();

        return self::formatCivilDate($days->toInt())
            . ' '
            . self::formatClock($secondsOfDay)
            . self::formatFraction($fraction, 9)
            . ($timezoneUtc ? '+00' : '');
    }

    /**
     * Formats a timestamp as an ISO-8601 UTC string, e.g.
     * 1970-01-01T00:00:01.000001Z.
     */
    public static function formatTimestampIso(int $value, TimestampUnit $unit): string
    {
        if ($value === PHP_INT_MAX) {
            return 'infinity';
        }
        if ($value === PHP_INT_MIN) {
            return '-infinity';
        }

        $nanos = self::toNanos($value, $unit);
        [$seconds, $fraction] = self::euclidDivMod(
            $nanos,
            BigInt::fromInt(self::NANOS_PER_SECOND),
        );
        [$days, $secondOfDay] = self::euclidDivMod(
            $seconds,
            BigInt::fromInt(self::SECONDS_PER_DAY),
        );

        return self::formatCivilDate($days->toInt())
            . 'T'
            . self::formatClock($secondOfDay->toInt())
            . self::formatFraction($fraction, 9)
            . 'Z';
    }

    /**
     * Formats a timestamp for a SQL literal, always with millisecond precision
     * and an explicit UTC offset when requested.
     */
    public static function formatTimestampLiteral(
        int $value,
        TimestampUnit $unit,
        bool $timezoneUtc,
    ): string {
        $micros = BigInt::fromInt($value);
        $micros = match ($unit) {
            TimestampUnit::Seconds => $micros->mul(BigInt::fromInt(1_000_000)),
            TimestampUnit::Millis => $micros->mul(BigInt::fromInt(1_000)),
            TimestampUnit::Micros => $micros,
            TimestampUnit::Nanos => $micros->divMod(BigInt::fromInt(self::NANOS_PER_MICRO))[0],
        };
        [$seconds, $fraction] = self::euclidDivMod(
            $micros,
            BigInt::fromInt(1_000_000),
        );
        [$days, $secondOfDay] = self::euclidDivMod(
            $seconds,
            BigInt::fromInt(self::SECONDS_PER_DAY),
        );
        $millis = $fraction->divMod(BigInt::fromInt(1_000))[0]->toInt();

        return self::formatCivilDate($days->toInt())
            . ' '
            . self::formatClock($secondOfDay->toInt())
            . '.' . str_pad((string) $millis, 3, '0', STR_PAD_LEFT)
            . ($timezoneUtc ? '+00' : '');
    }

    public static function formatInterval(IntervalValue $interval): string
    {
        $parts = [];
        if ($interval->months !== 0) {
            $years = intdiv($interval->months, 12);
            $months = $interval->months - $years * 12;
            if ($years !== 0) {
                $parts[] = self::intervalPart($years, 'year');
            }
            if ($months !== 0) {
                $parts[] = self::intervalPart($months, 'month');
            }
        }
        if ($interval->days !== 0) {
            $parts[] = self::intervalPart($interval->days, 'day');
        }
        if ($interval->micros !== 0) {
            $micros = $interval->micros;
            $sign = $micros < 0 ? '-' : '';
            $micros = abs($micros);
            $hours = intdiv($micros, 3_600_000_000);
            $micros %= 3_600_000_000;
            $minutes = intdiv($micros, 60_000_000);
            $micros %= 60_000_000;
            $seconds = intdiv($micros, 1_000_000);
            $micros %= 1_000_000;
            $time = sprintf('%s%02d:%02d:%02d', $sign, $hours, $minutes, $seconds);
            if ($micros !== 0) {
                $time .= '.' . rtrim(
                    str_pad((string) $micros, 6, '0', STR_PAD_LEFT),
                    '0',
                );
            }
            $parts[] = $time;
        }

        return $parts === [] ? '00:00:00' : implode(' ', $parts);
    }

    private static function intervalPart(int $value, string $name): string
    {
        $suffix = $value === 1 || $value === -1 ? '' : 's';

        return $value . ' ' . $name . $suffix;
    }

    private static function toNanos(int $value, TimestampUnit $unit): BigInt
    {
        $nanos = BigInt::fromInt($value);

        return match ($unit) {
            TimestampUnit::Seconds => $nanos->mul(BigInt::fromInt(self::NANOS_PER_SECOND)),
            TimestampUnit::Millis => $nanos->mul(BigInt::fromInt(1_000_000)),
            TimestampUnit::Micros => $nanos->mul(BigInt::fromInt(self::NANOS_PER_MICRO)),
            TimestampUnit::Nanos => $nanos,
        };
    }

    private static function formatClock(int $secondsOfDay): string
    {
        return sprintf(
            '%02d:%02d:%02d',
            intdiv($secondsOfDay, 3_600),
            intdiv($secondsOfDay % 3_600, 60),
            $secondsOfDay % 60,
        );
    }

    private static function formatFraction(BigInt $fraction, int $digits): string
    {
        if ($fraction->isZero()) {
            return '';
        }

        return '.' . rtrim(
            str_pad($fraction->__toString(), $digits, '0', STR_PAD_LEFT),
            '0',
        );
    }

    private static function formatTimeOfDay(BigInt $nanos): string
    {
        [$totalSeconds, $fraction] = self::euclidDivMod(
            $nanos,
            BigInt::fromInt(self::NANOS_PER_SECOND),
        );
        [$hours, $hourRemainder] = self::euclidDivMod(
            $totalSeconds,
            BigInt::fromInt(3_600),
        );
        [$minutes, $seconds] = self::euclidDivMod(
            $hourRemainder,
            BigInt::fromInt(60),
        );

        return sprintf(
            '%02d:%02d:%02d%s',
            $hours->toInt(),
            $minutes->toInt(),
            $seconds->toInt(),
            self::formatFraction($fraction, 9),
        );
    }

    private static function formatCivilDate(int $days): string
    {
        [$year, $month, $day] = self::civilFromDays($days);
        $yearText = $year < 0
            ? '-' . str_pad((string) -$year, 4, '0', STR_PAD_LEFT)
            : str_pad((string) $year, 4, '0', STR_PAD_LEFT);

        return sprintf('%s-%02d-%02d', $yearText, $month, $day);
    }

    /**
     * Converts days since 1970-01-01 to a proleptic Gregorian date.
     *
     * Howard Hinnant's civil_from_days algorithm. It is valid for the whole
     * DuckDB DATE range and avoids PHP's DateTime timezone handling.
     *
     * @return array{0:int,1:int,2:int}
     */
    private static function civilFromDays(int $days): array
    {
        $shifted = $days + 719_468;
        $era = self::floorDiv($shifted, 146_097);
        $dayOfEra = $shifted - $era * 146_097;
        $yearOfEra = intdiv(
            $dayOfEra
                - intdiv($dayOfEra, 1_460)
                + intdiv($dayOfEra, 36_524)
                - intdiv($dayOfEra, 146_096),
            365,
        );
        $year = $yearOfEra + $era * 400;
        $dayOfYear = $dayOfEra - (365 * $yearOfEra + intdiv($yearOfEra, 4) - intdiv($yearOfEra, 100));
        $monthPrime = intdiv(5 * $dayOfYear + 2, 153);
        $day = $dayOfYear - intdiv(153 * $monthPrime + 2, 5) + 1;
        $month = $monthPrime < 10 ? $monthPrime + 3 : $monthPrime - 9;
        if ($month <= 2) {
            $year++;
        }

        return [$year, $month, $day];
    }

    private static function floorDiv(int $dividend, int $divisor): int
    {
        $quotient = intdiv($dividend, $divisor);
        if (($dividend % $divisor) < 0) {
            $quotient--;
        }

        return $quotient;
    }

    /**
     * @return array{0:BigInt,1:BigInt}
     */
    private static function euclidDivMod(BigInt $value, BigInt $divisor): array
    {
        [$quotient, $remainder] = $value->divMod($divisor);
        if ($remainder->isNegative()) {
            $quotient = $quotient->sub(BigInt::one());
            $remainder = $remainder->add($divisor);
        }

        return [$quotient, $remainder];
    }
}
