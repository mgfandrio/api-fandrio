<?php

namespace App\Helpers;

use Carbon\Carbon;

class DateFormatter
{
    /**
     * Formate une date de manière sécurisée
     * Gère les cas où la date peut être une chaîne, un objet Carbon ou NULL
     * 
     * @param mixed $date La date à formatter (string, Carbon, DateTime ou null)
     * @param string $format Le format de sortie souhaité
     * @return string|null La date formatée ou null si erreur
     */
    public static function format($date, string $format = 'Y-m-d H:i:s'): ?string
    {
        if (is_null($date)) {
            return null;
        }

        try {
            // Si c'est un objet avec méthode format (Carbon, DateTime)
            if (is_object($date) && method_exists($date, 'format')) {
                return $date->format($format);
            }

            // Si c'est une chaîne, convertir en Carbon puis formater
            if (is_string($date)) {
                return Carbon::parse($date)->format($format);
            }

            return null;
        } catch (\Exception $e) {
            \Log::warning('Erreur lors du formatage de la date', ['date' => $date, 'error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Raccourci pour format Y-m-d
     */
    public static function formatDate($date): ?string
    {
        return self::format($date, 'Y-m-d');
    }

    /**
     * Raccourci pour format Y-m-d H:i:s
     */
    public static function formatDateTime($date): ?string
    {
        return self::format($date, 'Y-m-d H:i:s');
    }

    /**
     * Raccourci pour format d/m/Y
     */
    public static function formatDateFR($date): ?string
    {
        return self::format($date, 'd/m/Y');
    }
}
