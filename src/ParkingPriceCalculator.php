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
 * @phpstan-type RuleCalcResult array{total: int, consumed_first: int, consumed_next: int, rule: PricingRule}
 *
 * @phpstan-type RoundingStrategy callable(int): int
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

    public const int|float MAX_PARKING_TIME_IN_MINUTES = 60 * 24 * 3;

    /**
     * @param PricingRule[] $rules
     * @param string $startAt
     * @param string $endAt
     * @param string $currency
     * @param RoundingStrategy|null $roundingStrategy
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
        if (count($rules) === 0) {
            throw new DomainException('Rules array cannot be empty', 1);
        }

        $durationMinutes = $this->parseTimeAndGetDurationInMinutes($startAt, $endAt);

        if ($durationMinutes > self::MAX_PARKING_TIME_IN_MINUTES) {
            throw new DomainException('Parking time cannot be longer than 72 hours', 3);
        }

        $result = $this->calculateAndGetResultWithBestPrice($rules, $durationMinutes, $this->getRoundingStrategyWithDefault($roundingStrategy));
        $resultRule = $rules[$result['rule_index']];

        return [
            'total' => $result['total'],
            'currency' => 'PLN',
            'rule_index' => $result['rule_index'],
            'periods' => [
                'period' => $resultRule['period'],
                'first_period_price' => $resultRule['first_period_price'],
                'next_periods_price' => $resultRule['next_periods_price'],
                'consumed_first' => $result['consumed_first'],
                'consumed_next' => $result['consumed_next'],
            ],
        ];
    }

    /**
     * @param PricingRule[] $rules
     * @param int $durationMinutes
     * @param RoundingStrategy $roundingStrategy
     * @return array{total: int, consumed_first: int, consumed_next: int, rule_index: int}
     */
    private function calculateAndGetResultWithBestPrice(array $rules, int $durationMinutes, callable $roundingStrategy): array
    {
        $bestResult = null;
        $ruleIndex = null;

        foreach ($rules as $index => $rule) {
            $result = $this->calculateForRule($rule, $durationMinutes, $roundingStrategy);

            if (!$this->isOptimalRule($bestResult, $result)) {
                continue;
            }

            $ruleIndex = $index;
            $bestResult = $result;
        }
        /** @var RuleCalcResult $bestPriceResult */

        return [
            'rule_index' => $ruleIndex,
            ...$bestResult
        ];
    }

    /**
     * @param PricingRule $rule
     * @param int $durationMinutes
     * @param RoundingStrategy $roundingStrategy
     * @return RuleCalcResult
     */
    private function calculateForRule(array $rule, int $durationMinutes, callable $roundingStrategy): array
    {
        $period = $rule['period'];
        $firstPrice = $rule['price_first_period'];
        $nextPrice = $rule['price_next_periods'];

        $consumedFirst = $durationMinutes > 0 ? 1 : 0;
        $remaining = max(0, $durationMinutes - $period);
        $consumedNext = $roundingStrategy($remaining / $period);

        $total = ($consumedFirst ? $firstPrice : 0) + $consumedNext * $nextPrice;

        return [
            'total' => $total,
            'consumed_first' => $consumedFirst,
            'consumed_next' => $consumedNext,
            'rule' => $rule
        ];
    }

    private function parseDateTime(string $datetime): CarbonImmutable
    {
        return CarbonImmutable::parse($datetime, self::ZONE);
    }

    /**
     * @param RoundingStrategy|null $roundingStrategy
     * @return RoundingStrategy
     */
    private function getRoundingStrategyWithDefault(?callable $roundingStrategy = null): callable
    {
        if (null === $roundingStrategy) {
            return static fn(int $x) => (int)ceil($x);
        }

        return $roundingStrategy;
    }

    private function parseTimeAndGetDurationInMinutes(string $startAt, string $endAt): int
    {
        $minutes = $this->getDurationInMinutes(
            $this->parseDateTime($startAt),
            $this->parseDateTime($endAt)
        );
        if ($minutes <= 0) {
            throw new DomainException('endAt must be greater than startAt', 2);
        }
        return $minutes;
    }

    private function getDurationInMinutes(CarbonImmutable $startAt, CarbonImmutable $endAt): int
    {
        return (int)round($startAt->diffInMinutes($endAt));
    }

    /**
     * @param ?RuleCalcResult $best
     * @param RuleCalcResult $current
     * @return bool
     */
    private function isOptimalRule(?array $best, array $current): bool
    {
        if(!$best) {
            return true;
        }
        if($current['total'] < $best['total']) {
            return  true;
        }

        if($current['rule']['price_first_period'] < $best['rule']['price_first_period']) {
            return true;
        }

        if($current['rule']['period'] < $best['rule']['period']) {
            return true;
        }

        return false;
    }
}