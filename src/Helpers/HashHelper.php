<?php

namespace Neiderruiz\TranslateProjectsLaravel\Helpers;

class HashHelper
{
    /**
     * SHA-256 → Base36, primeros $length caracteres.
     * Debe coincidir byte a byte con helpers/hash_utils.py del backend.
     */
    public static function generateHashText(string $text, int $length = 15): string
    {
        // Base36 por división larga sobre los bytes, para no depender de gmp ni bcmath
        $bytes = array_values(unpack('C*', hash('sha256', $text, true)));
        $result = '';

        while ($bytes) {
            $remainder = 0;
            $quotient = [];
            foreach ($bytes as $byte) {
                $value = ($remainder << 8) | $byte;
                $digit = intdiv($value, 36);
                $remainder = $value % 36;
                if ($quotient || $digit) {
                    $quotient[] = $digit;
                }
            }
            $result = '0123456789abcdefghijklmnopqrstuvwxyz'[$remainder] . $result;
            $bytes = $quotient;
        }

        return substr($result ?: '0', 0, $length);
    }
}
