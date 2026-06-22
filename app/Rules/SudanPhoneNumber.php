<?php

namespace App\Rules;

use Illuminate\Contracts\Validation\Rule;

class SudanPhoneNumber implements Rule
{
    public function passes($attribute, $value)
    {
        if (!is_string($value)) {
            return false;
        }

        // Remove all non-digit characters (including +)
        $cleaned = preg_replace('/[^0-9]/', '', $value);

        // Sudan phone number format: 0XXXXXXXXX (10 digits starting with 0)
        // Also accept formats that can be normalized to this:
        // 249XXXXXXXXX (international format without +)
        // 9XXXXXXXXX (local format without leading 0)

        // Check for correct local format: 0XXXXXXXXX (10 digits starting with 0)
        if (preg_match('/^0[0-9]{9}$/', $cleaned)) {
            return true;
        }

        // Check for international format 249XXXXXXXXX (12 digits total)
        if (preg_match('/^249[0-9]{9}$/', $cleaned)) {
            return true;
        }

        // Check for format without leading 0: 9XXXXXXXXX (9 digits)
        if (preg_match('/^9[0-9]{8}$/', $cleaned)) {
            return true;
        }

        return false;
    }

    public function message()
    {
        return 'The :attribute must be a valid Sudan phone number.';
    }

    public static function normalize($phone)
    {
        // Remove all non-digit characters (including +)
        $cleaned = preg_replace('/[^0-9]/', '', $phone);

        // Convert to local format: 0XXXXXXXXX (10 digits starting with 0)
        
        // If already in correct format: 0XXXXXXXXX
        if (preg_match('/^0[0-9]{9}$/', $cleaned)) {
            return $cleaned;
        }

        // Convert from international format: 249XXXXXXXXX -> 0XXXXXXXXX
        if (preg_match('/^249([0-9]{9})$/', $cleaned, $matches)) {
            return '0' . $matches[1];
        }

        // Convert from format without leading 0: 9XXXXXXXXX -> 09XXXXXXXXX
        if (preg_match('/^9([0-9]{8})$/', $cleaned)) {
            return '0' . $cleaned;
        }

        return $phone; // Return original if can't normalize
    }
}

