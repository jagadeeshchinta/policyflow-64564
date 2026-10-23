<?php

namespace App\Services;

use InvalidArgumentException;

class PremiumCalculator
{
    /**
     * Base premium per travel day by plan code.
     */
    private const BASE_RATES = [
        'BASIC' => '12.00',
        'PLUS' => '20.00',
        'PREMIUM' => '30.00',
    ];

    /**
     * Coverage multipliers by sum_insured value.
     */
    private const COVERAGE_MULTIPLIERS = [
        '100000' => '1.00',
        '300000' => '1.25',
        '500000' => '1.50',
    ];

    /**
     * Destination multipliers.
     */
    private const DESTINATION_MULTIPLIERS = [
        'DOMESTIC' => '1.00',
        'INTERNATIONAL' => '1.80',
    ];

    /**
     * Calculate premium using bcmath for decimal-safe arithmetic.
     *
     * @param string $planCode   BASIC, PLUS, or PREMIUM
     * @param string $dateOfBirth Y-m-d format
     * @param string $tripStart  Y-m-d format
     * @param string $tripEnd    Y-m-d format
     * @param string $destination DOMESTIC or INTERNATIONAL
     * @param string $sumInsured Numeric value as string
     * @return array Premium details including breakdown
     */
    public function calculate(
        string $planCode,
        string $dateOfBirth,
        string $tripStart,
        string $tripEnd,
        string $destination,
        string $sumInsured
    ): array {
        // Validate plan code
        if (!isset(self::BASE_RATES[$planCode])) {
            throw new InvalidArgumentException(
                "Unsupported plan code: {$planCode}. Supported plans: BASIC, PLUS, PREMIUM."
            );
        }

        // Validate destination
        if (!isset(self::DESTINATION_MULTIPLIERS[$destination])) {
            throw new InvalidArgumentException(
                "Unsupported destination: {$destination}. Supported: DOMESTIC, INTERNATIONAL."
            );
        }

        // Validate and get coverage multiplier
        $sumInsuredKey = rtrim(rtrim($sumInsured, '0'), '.');
        // Normalize: remove decimals for lookup
        $sumInsuredInt = (string) intval(floatval($sumInsured));
        if (!isset(self::COVERAGE_MULTIPLIERS[$sumInsuredInt])) {
            throw new InvalidArgumentException(
                "Unsupported sum insured: {$sumInsured}. Supported values: 100000, 300000, 500000."
            );
        }

        // Calculate travel days (inclusive)
        $start = new \DateTimeImmutable($tripStart);
        $end = new \DateTimeImmutable($tripEnd);
        $diffDays = (int) $start->diff($end)->days + 1;
        $travelDays = max(1, $diffDays);

        // Calculate age at trip start
        $dob = new \DateTimeImmutable($dateOfBirth);
        $age = (int) $dob->diff($start)->y;

        // Get multipliers
        $baseDailyRate = self::BASE_RATES[$planCode];
        $ageMultiplier = $this->getAgeMultiplier($age);
        $destinationMultiplier = self::DESTINATION_MULTIPLIERS[$destination];
        $coverageMultiplier = self::COVERAGE_MULTIPLIERS[$sumInsuredInt];

        // Calculate premium: base_daily_rate × travel_days × age_multiplier × destination_multiplier × coverage_multiplier
        // Using bcmath for decimal-safe arithmetic
        $premium = $baseDailyRate;
        $premium = bcmul($premium, (string) $travelDays, 4);
        $premium = bcmul($premium, $ageMultiplier, 4);
        $premium = bcmul($premium, $destinationMultiplier, 4);
        $premium = bcmul($premium, $coverageMultiplier, 4);

        // Round to 2 decimal places
        $premium = $this->bcround($premium, 2);

        return [
            'premium' => $premium,
            'travel_days' => $travelDays,
            'age' => $age,
            'base_daily_rate' => $baseDailyRate,
            'age_multiplier' => $ageMultiplier,
            'destination_multiplier' => $destinationMultiplier,
            'coverage_multiplier' => $coverageMultiplier,
        ];
    }

    /**
     * Get age multiplier based on age at trip start.
     */
    private function getAgeMultiplier(int $age): string
    {
        if ($age >= 0 && $age <= 17) {
            return '1.10';
        } elseif ($age >= 18 && $age <= 60) {
            return '1.00';
        } elseif ($age >= 61 && $age <= 70) {
            return '1.50';
        } else { // 71+
            return '2.00';
        }
    }

    /**
     * Round a bcmath number to specified decimal places.
     */
    private function bcround(string $number, int $precision): string
    {
        if ($precision < 0) {
            return $number;
        }

        $multiply = bcpow('10', (string) $precision, 0);
        $shifted = bcmul($number, $multiply, 10);

        // Add 0.5 and floor for rounding
        $shifted = bcadd($shifted, '0.5', 0);

        return bcdiv($shifted, $multiply, $precision);
    }
}
