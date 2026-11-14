<?php

namespace App\Service;

/**
 * Génère la partie aléatoire d'un lien de partage (ex : "UhGyr4Kd").
 * 8 caractères parmi 62 : environ 218 000 milliards de combinaisons, donc des liens
 * impossibles à deviner. random_int() est cryptographiquement sûr.
 */
class ShareTokenGenerator
{
    public const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
    public const LENGTH = 8;

    public function generate(): string
    {
        $token = '';
        $max = strlen(self::ALPHABET) - 1;
        for ($i = 0; $i < self::LENGTH; ++$i) {
            $token .= self::ALPHABET[random_int(0, $max)];
        }

        return $token;
    }
}
