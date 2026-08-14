<?php

namespace App\Services;

class MobileNumberService
{
    public function normalize(?string $number): ?string
    {
        if (blank($number)) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $number);

        if (strlen($digits) === 12 && str_starts_with($digits, '91')) {
            $digits = substr($digits, 2);
        }

        if (strlen($digits) === 11 && str_starts_with($digits, '0')) {
            $digits = substr($digits, 1);
        }

        return $digits ?: null;
    }

    public function isValidIndianMobile(?string $number): bool
    {
        $normalized = $this->normalize($number);

        return $normalized !== null && preg_match('/^[6-9][0-9]{9}$/', $normalized) === 1;
    }

    public function waMeNumber(?string $number): ?string
    {
        $normalized = $this->normalize($number);

        return $normalized ? '91' . $normalized : null;
    }
}
