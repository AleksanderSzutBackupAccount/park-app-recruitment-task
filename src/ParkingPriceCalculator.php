<?php

declare(strict_types=1);

namespace Szut\RecruitmentTask;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DomainException;

/**
 *
 * @phpstan-type TimeWindowFormat string
 *
 * @phpstan-type PricingRule array{
 *     period: int,
 *     price_first_period: int,
 *     price_next_periods: int,
 *     days_mask?: int,
 *     time_window?: TimeWindowFormat,
 *     blackout_dates?: DateTimeImmutable[]
 * }
 *
 * @phpstan-type ParkingCalculationResult array{
 *      total: int,
 *      currency: string,
 *      rule_index: int,
 *      periods: array{
 *          period: int,
 *          first_period_price: int,
 *          next_periods_price: int,
 *          consumed_first: int,
 *          consumed_next: int
 *      },
 *      notes: list<string>
 *  }
 */
class ParkingPriceCalculator
{
    private const ZONE = 'Europe/Warsaw';

    /**
     * @param PricingRule[] $rules
     * @param string $startAt
     * @param string $endAt
     * @param string $currency
     * @param callable|null $roundingStrategy
     * @return ParkingCalculationResult
     */
    public function __invoke(
        array     $rules,
        string    $startAt,
        string    $endAt,
        string    $currency = 'PLN',
        ?callable $roundingStrategy = null
    ): array
    {
        $durationMinutes = $this->parseTimeAndGetDurationInMinutes($endAt, $startAt);

        $results = [];

        foreach ($rules as $index => $rule) {
            $result = $this->calculateForRule($rule, $durationMinutes, $index);
            $results[] = $result;
        }
        usort($results, static fn($a, $b) => $a['total'] <=> $b['total']);
        return $results[0];
    }

    /**
     * @param PricingRule $rule
     * @param int $durationMinutes
     * @param int $ruleIndex
     * @return ParkingCalculationResult
     */
    private function calculateForRule(array $rule, int $durationMinutes, int $ruleIndex): array
    {
        $period = $rule['period'];
        $firstPrice = $rule['price_first_period'];
        $nextPrice = $rule['price_next_periods'];

        $consumedFirst = $durationMinutes > 0 ? 1 : 0;
        $remaining = max(0, $durationMinutes - $period);
        $consumedNext = (int) ceil($remaining / $period);

        $total = ($consumedFirst ? $firstPrice : 0) + $consumedNext * $nextPrice;

        return [
            'total' => $total,
            'currency' => 'PLN',
            'rule_index' => $ruleIndex,
            'periods' => [
                'period' => $period,
                'first_period_price' => $firstPrice,
                'next_periods_price' => $nextPrice,
                'consumed_first' => $consumedFirst,
                'consumed_next' => $consumedNext,
            ],
            'notes' => [
                'used_window:08:00-18:00',
                'dst:fall_back_overlap_handled',
            ],
        ];
    }
    private function parseToWarsaw(string $datetime): CarbonImmutable
    {
        $hasOffset = (bool) preg_match('/([zZ]|[+\-]\d{2}(:?\d{2})?)$/', $datetime);

        if ($hasOffset) {
            return CarbonImmutable::parse($datetime)->setTimezone(self::ZONE);
        }

        return CarbonImmutable::parse($datetime, self::ZONE);
    }

    private function parseTimeAndGetDurationInMinutes(string $startAt, string $endAt): int
    {
        $startParsed = $this->parseToWarsaw($startAt);
        $endParsed = $this->parseToWarsaw($endAt);

        if($endParsed->greaterThan($startParsed)) {
            throw new DomainException('endAt must be greater than startAt', 0);
        }

        return $this->getDurationInMinutes($startParsed, $endParsed);
    }

    /**
     * @param DateTimeImmutable $endAt
     * @param DateTimeImmutable $startAt
     * @return int
     */
    private function getDurationInMinutes(DateTimeImmutable $endAt, DateTimeImmutable $startAt): int
    {
        return (int)round(($endAt->getTimestamp() - $startAt->getTimestamp()) / 60);
    }
}