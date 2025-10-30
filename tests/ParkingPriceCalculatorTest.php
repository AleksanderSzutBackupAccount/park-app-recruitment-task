<?php

declare(strict_types=1);

use Carbon\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Szut\RecruitmentTask\ParkingPriceCalculator;

/**
 * @phpstan-import-type PricingRule from ParkingPriceCalculator
 */
final class ParkingPriceCalculatorTest extends TestCase
{
    private const int HOUR_IN_MINUTES = 60;

    private ParkingPriceCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calculator = new ParkingPriceCalculator();
    }

    /**
     * @param PricingRule[] $rules
     * @param string $start
     * @param string $end
     * @param int $expectedTotal
     * @param string|null $expectedNote
     */
    #[DataProvider('provideParkingScenarios')]
    public function test_parking_price_calculation(
        array   $rules,
        string  $start,
        string  $end,
        int     $expectedTotal,
        ?string $expectedNote = null
    ): void {
        $result = ($this->calculator)(
            $rules,
            $start,
            $end
        );

        $this->assertSame($expectedTotal, $result['total']);
        $this->assertSame(0, $result['rule_index']);
        $this->assertSame(30, $result['periods']['period']);

        if ($expectedNote) {
            $this->assertIsArray($result['notes']);
            $this->assertContains($expectedNote, $result['notes']);
        }
    }

    public function test_throw_error_on_empty_rules(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionCode(1);

        ($this->calculator)(
            [],
            Carbon::now()->addHour()->toIso8601String(),
            Carbon::now()->toIso8601String(),
        );
    }

    public function test_throw_error_on_invalid_date(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionCode(2);

        ($this->calculator)(
            [[]],
            Carbon::now()->addHour()->toIso8601String(),
            Carbon::now()->toIso8601String(),
        );
    }

    public function test_throw_error_on_too_long_parking_time(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionCode(3);

        ($this->calculator)(
            [[]],
            Carbon::now()->toIso8601String(),
            Carbon::now()->addMinutes(ParkingPriceCalculator::MAX_PARKING_TIME_IN_MINUTES + 2)->toIso8601String(),
        );
    }

    /**
     * @return array<array{rules: PricingRule[], start: string, end: string, expectedTotal: int, expectedNote?: string }>
     */
    public static function provideParkingScenarios(): array
    {
        $firstPricePeriod = 500;
        $nextPricePeriod = 200;
        $periodHalfHour = self::HOUR_IN_MINUTES / 2;
        $dailyPeriod = self::HOUR_IN_MINUTES * 24;

        $rules = [
            [
                'period' => $periodHalfHour,
                'price_first_period' => $firstPricePeriod,
                'price_next_periods' => $nextPricePeriod
            ],
            [
                'period' => $dailyPeriod,
                'price_first_period' => $firstPricePeriod * 20, // 4hour extra
                'price_next_periods' => $nextPricePeriod * 20 // 4hour extra
            ]
        ];

        return [
            'simple calculation' => [
                'rules' => $rules,
                'start' => '2025-10-29T10:00:00',
                'end' => '2025-10-29T12:00:00',
                // 2h = 120min → first(30min) + 3 next(30min) = 500 + 3×200 = 1100
                'expectedTotal' => $firstPricePeriod + $nextPricePeriod * 3,
            ],
            'calculation with offset difference' => [
                'rules' => $rules,
                'start' => '2025-10-29T10:00:00+00:00', // UTC → 11:00 in Warsaw
                'end' => '2025-10-29T12:00:00', // treated as Warsaw local
                // real duration ~1h → first(30) + 1 next(30)
                'expectedTotal' => $firstPricePeriod + $nextPricePeriod,
            ],
            'DST fall back (repeat hour)' => [
                'rules' => $rules,
                'start' => '2025-10-26T01:30:00', // Europe/Warsaw DST
                'end' => '2025-10-26T03:30:00',
                'expectedTotal' => $firstPricePeriod + $nextPricePeriod * 5,
            ],
        ];
    }

    /**
     * @param callable $roundingStrategy
     * @param float $minutes
     * @param int $expectedConsumed
     */
    #[DataProvider('provideRoundingStrategies')]
    public function test_rounding_strategies(callable $roundingStrategy, float $minutes, int $expectedConsumed): void
    {
        $rule = [
            'period' => 30,
            'price_first_period' => 500,
            'price_next_periods' => 200
        ];

        $method = new ReflectionMethod(ParkingPriceCalculator::class, 'calculateForRule');
        $method->setAccessible(true);

        $result = $method->invoke(
            $this->calculator,
            $rule,
            (int)$minutes,
            $roundingStrategy
        );

        $this->assertSame($expectedConsumed, $result['consumed_next']);
    }

    public static function provideRoundingStrategies(): array
    {
        return [
            'ceil strategy' => [
                fn(int|float $x): int => (int)ceil($x),
                31,
                1
            ],
            'floor strategy' => [
                fn(int|float $x): int => (int)floor($x),
                59,
                0
            ],
            'custom half strategy' => [
                fn(int|float $x): int => (int)($x >= 0.5 ? ceil($x) : floor($x)),
                45,
                1
            ],
        ];
    }
}
