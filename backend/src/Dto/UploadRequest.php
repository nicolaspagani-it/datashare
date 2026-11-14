<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Les champs texte de POST /api/files (le fichier lui-même est vérifié à part, dans le contrôleur).
 *
 * "expiration" = nombre de jours de conservation : de 1 (une journée) à 7 (une semaine).
 * "password" est optionnel : vide ou absent = fichier sans mot de passe.
 */
final class UploadRequest
{
    public const EXPIRATION_DAYS = ['1', '2', '3', '4', '5', '6', '7'];

    public function __construct(
        #[Assert\Sequentially([
            new Assert\NotBlank(message: 'Choisis une durée de conservation.'),
            new Assert\Choice(
                choices: self::EXPIRATION_DAYS,
                message: 'La durée de conservation doit être comprise entre 1 jour et 1 semaine (7 jours).',
            ),
        ])]
        public readonly string $expiration = '',

        #[Assert\Length(max: 4096, maxMessage: 'Le mot de passe est trop long ({{ limit }} caractères maximum).')]
        public readonly string $password = '',
    ) {
    }
}
