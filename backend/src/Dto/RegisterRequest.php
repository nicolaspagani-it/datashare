<?php

namespace App\Dto;

use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Données reçues par POST /api/register.
 *
 * Chaque contrainte a un message explicite en français : il est renvoyé tel quel au
 * frontend, qui l'affiche sous le champ concerné (email ou password).
 *
 * "Sequentially" exécute les contraintes dans l'ordre et s'arrête à la première qui échoue :
 * un champ vide affiche seulement "obligatoire", et pas en plus "trop court" ou "invalide".
 *
 * Les attributs #[OA\...] décrivent le corps de la requête dans la documentation OpenAPI
 * (le bundle ne lit pas les contraintes enveloppées dans Sequentially : on les répète ici).
 */
#[OA\Schema(description: "Corps de la requête d'inscription.", required: ['email', 'password'])]
final class RegisterRequest
{
    public function __construct(
        #[Assert\Sequentially([
            new Assert\NotBlank(message: "L'adresse email est obligatoire."),
            new Assert\Email(message: "L'adresse email n'est pas valide (exemple : nom@domaine.fr)."),
            new Assert\Length(max: 180, maxMessage: "L'adresse email est trop longue ({{ limit }} caractères maximum)."),
        ])]
        #[OA\Property(description: "Adresse email du compte (unique : elle sert d'identifiant de connexion).", format: 'email', maxLength: 180, example: 'claire@example.com')]
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
        #[OA\Property(description: 'Mot de passe : 8 caractères au minimum. Il est haché avant d\'être stocké.', format: 'password', minLength: 8, maxLength: 4096, example: 'mon-mot-de-passe')]
        public readonly string $password = '',
    ) {
    }
}
