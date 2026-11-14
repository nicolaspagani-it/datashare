<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Données reçues par POST /api/register.
 *
 * Chaque contrainte a un message explicite en français : il est renvoyé tel quel au
 * frontend, qui l'affiche sous le champ concerné (email ou password).
 *
 * "Sequentially" exécute les contraintes dans l'ordre et s'arrête à la première qui échoue :
 * un champ vide affiche seulement "obligatoire", et pas en plus "trop court" ou "invalide".
 */
final class RegisterRequest
{
    public function __construct(
        #[Assert\Sequentially([
            new Assert\NotBlank(message: "L'adresse email est obligatoire."),
            new Assert\Email(message: "L'adresse email n'est pas valide (exemple : nom@domaine.fr)."),
            new Assert\Length(max: 180, maxMessage: "L'adresse email est trop longue ({{ limit }} caractères maximum)."),
        ])]
        public readonly string $email = '',

        #[Assert\Sequentially([
            new Assert\NotBlank(message: 'Le mot de passe est obligatoire.'),
            new Assert\Length(
                min: 8,
                max: 4096,
                minMessage: 'Le mot de passe est trop court : il doit contenir au moins {{ limit }} caractères.',
                maxMessage: 'Le mot de passe est trop long ({{ limit }} caractères maximum).',
            ),
        ])]
        public readonly string $password = '',
    ) {
    }
}
