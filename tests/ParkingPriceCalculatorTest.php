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
    ): void
    {
        $result = ($this->calculator)(
            $rules,
            $start,
            $end
        );

        $this->assertSame($expectedTotal, $result['total']);
        $this->assertSame(0, $result['rule_index']);
        $this->assertSame(30, $result['periods']['period']);

        if ($expectedNote) {
            $this->assertContains($expectedNote, $result['notes']);
        }
    }

    public function test_throw_error_on_invalid_date(): void
    {

        $this->expectException(DomainException::class);
        $this->expectExceptionCode(0);

        ($this->calculator)(
            [],
            Carbon::now()->addHour()->toIso8601String(),
            Carbon::now()->toIso8601String(),
        );

    }

    /**
     * @return array<array{rules: PricingRule[], start: string, end: string, expectedTotal: int, expectedNote?: string }>
     */
    public static function provideParkingScenarios(): array
    {
        $firstPricePeriod = 500;
        $nextPricePeriod = 200;
        $period = 30;

        $rules = [
            [
                'period' => $period,
                'price_first_period' => $firstPricePeriod,
                'price_next_periods' => $nextPricePeriod
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
                'start' => '2025-10-26T01:30:00+02:00', // Europe/Warsaw DST
                'end' => '2025-10-26T03:30:00+01:00',
                'expectedTotal' => $firstPricePeriod + $nextPricePeriod * 5, // 6 periods = first + 5 next
                'expectedNote' => 'dst:fall_back_overlap_handled'
            ],
        ];
    }
}
