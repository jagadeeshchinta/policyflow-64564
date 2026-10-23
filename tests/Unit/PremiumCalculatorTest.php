<?php

namespace Tests\Unit;

use App\Services\PremiumCalculator;
use PHPUnit\Framework\TestCase;

class PremiumCalculatorTest extends TestCase
{
    private PremiumCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calculator = new PremiumCalculator();
    }

    /**
     * Test the example from the assignment spec:
     * PLUS plan, 5 days, age 28, INTERNATIONAL, ₹300,000 → ₹225.00
     */
    public function test_example_from_assignment_spec(): void
    {
        $result = $this->calculator->calculate(
            planCode: 'PLUS',
            dateOfBirth: '1998-04-10',
            tripStart: '2026-11-10',
            tripEnd: '2026-11-14',
            destination: 'INTERNATIONAL',
            sumInsured: '300000'
        );

        $this->assertEquals('225.00', $result['premium']);
        $this->assertEquals(5, $result['travel_days']);
        $this->assertEquals('1.00', $result['age_multiplier']);
        $this->assertEquals('1.80', $result['destination_multiplier']);
        $this->assertEquals('1.25', $result['coverage_multiplier']);
        $this->assertEquals('20.00', $result['base_daily_rate']);
    }

    /**
     * Test BASIC plan, domestic, minimum coverage.
     * 3 days, age 25, DOMESTIC, ₹100,000
     * = 12 × 3 × 1.00 × 1.00 × 1.00 = 36.00
     */
    public function test_basic_plan_domestic_minimum_coverage(): void
    {
        $result = $this->calculator->calculate(
            planCode: 'BASIC',
            dateOfBirth: '2001-01-01',
            tripStart: '2026-12-01',
            tripEnd: '2026-12-03',
            destination: 'DOMESTIC',
            sumInsured: '100000'
        );

        $this->assertEquals('36.00', $result['premium']);
        $this->assertEquals(3, $result['travel_days']);
    }

    /**
     * Test PREMIUM plan with high coverage and age multiplier.
     * 10 days, age 65, INTERNATIONAL, ₹500,000
     * = 30 × 10 × 1.50 × 1.80 × 1.50 = 1215.00
     */
    public function test_premium_plan_senior_international_max_coverage(): void
    {
        $result = $this->calculator->calculate(
            planCode: 'PREMIUM',
            dateOfBirth: '1961-06-15',
            tripStart: '2026-12-01',
            tripEnd: '2026-12-10',
            destination: 'INTERNATIONAL',
            sumInsured: '500000'
        );

        $this->assertEquals('1215.00', $result['premium']);
        $this->assertEquals(10, $result['travel_days']);
        $this->assertEquals('1.50', $result['age_multiplier']);
    }

    /**
     * Test child age multiplier (0-17 → 1.10).
     * BASIC, 2 days, age 10, DOMESTIC, ₹100,000
     * = 12 × 2 × 1.10 × 1.00 × 1.00 = 26.40
     */
    public function test_child_age_multiplier(): void
    {
        $result = $this->calculator->calculate(
            planCode: 'BASIC',
            dateOfBirth: '2016-06-15',
            tripStart: '2026-12-01',
            tripEnd: '2026-12-02',
            destination: 'DOMESTIC',
            sumInsured: '100000'
        );

        $this->assertEquals('26.40', $result['premium']);
        $this->assertEquals('1.10', $result['age_multiplier']);
    }

    /**
     * Test elderly age multiplier (71+ → 2.00).
     * PLUS, 1 day, age 75, DOMESTIC, ₹300,000
     * = 20 × 1 × 2.00 × 1.00 × 1.25 = 50.00
     */
    public function test_elderly_age_multiplier(): void
    {
        $result = $this->calculator->calculate(
            planCode: 'PLUS',
            dateOfBirth: '1951-01-01',
            tripStart: '2026-12-01',
            tripEnd: '2026-12-01',
            destination: 'DOMESTIC',
            sumInsured: '300000'
        );

        $this->assertEquals('50.00', $result['premium']);
        $this->assertEquals('2.00', $result['age_multiplier']);
        $this->assertEquals(1, $result['travel_days']);
    }

    /**
     * Test single day trip (minimum 1 day).
     */
    public function test_single_day_trip(): void
    {
        $result = $this->calculator->calculate(
            planCode: 'BASIC',
            dateOfBirth: '2000-01-01',
            tripStart: '2026-12-01',
            tripEnd: '2026-12-01',
            destination: 'DOMESTIC',
            sumInsured: '100000'
        );

        $this->assertEquals(1, $result['travel_days']);
        $this->assertEquals('12.00', $result['premium']);
    }

    /**
     * Test unsupported plan code throws exception.
     */
    public function test_unsupported_plan_code_throws_exception(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported plan code: GOLD');

        $this->calculator->calculate(
            planCode: 'GOLD',
            dateOfBirth: '2000-01-01',
            tripStart: '2026-12-01',
            tripEnd: '2026-12-03',
            destination: 'DOMESTIC',
            sumInsured: '100000'
        );
    }

    /**
     * Test unsupported sum insured throws exception.
     */
    public function test_unsupported_sum_insured_throws_exception(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported sum insured');

        $this->calculator->calculate(
            planCode: 'BASIC',
            dateOfBirth: '2000-01-01',
            tripStart: '2026-12-01',
            tripEnd: '2026-12-03',
            destination: 'DOMESTIC',
            sumInsured: '200000'
        );
    }

    /**
     * Test unsupported destination throws exception.
     */
    public function test_unsupported_destination_throws_exception(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported destination');

        $this->calculator->calculate(
            planCode: 'BASIC',
            dateOfBirth: '2000-01-01',
            tripStart: '2026-12-01',
            tripEnd: '2026-12-03',
            destination: 'MARS',
            sumInsured: '100000'
        );
    }
}
