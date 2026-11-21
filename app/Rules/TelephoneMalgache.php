<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Numéro de téléphone malgache local : 10 chiffres commençant par 03
 * (ex. 034 12 345 67). Les espaces, points et tirets sont tolérés.
 */
class TelephoneMalgache implements ValidationRule
{
    public const MESSAGE = 'Le numéro de téléphone doit contenir 10 chiffres et commencer par 03 (ex. 034 12 345 67).';

    public static function nettoyer(?string $valeur): string
    {
        return preg_replace('/[\s.\-]/', '', (string) $valeur);
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!is_string($value) || !preg_match('/^03\d{8}$/', self::nettoyer($value))) {
            $fail(self::MESSAGE);
        }
    }
}
