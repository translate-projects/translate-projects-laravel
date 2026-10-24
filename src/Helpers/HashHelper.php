<?php

namespace Neiderruiz\TranslateProjectsLaravel\Helpers;

class HashHelper
{
    /**
     * Convierte un número a Base36
     */
    private static function base36Encode(string $hex): string
    {
        $decimal = gmp_init($hex, 16);
        return gmp_strval($decimal, 36);
    }

    /**
     * Genera un hash corto de un texto
     */
    public static function generateHashText(string $text, int $length = 15): string
    {
        // Generar hash SHA-256
        $hashBinary = hash('sha256', $text, true);

        // Convertir a una cadena hexadecimal
        $hashHex = bin2hex($hashBinary);

        // Convertir el hash hexadecimal a Base36
        $base36Hash = self::base36Encode($hashHex);

        // Retornar solo los primeros caracteres del hash
        return substr($base36Hash, 0, $length);
    }
}
