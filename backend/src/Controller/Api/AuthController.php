<?php

namespace App\Controller\Api;

use App\Dto\RegisterRequest;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Nelmio\ApiDocBundle\Attribute\Security;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class AuthController extends AbstractController
{
    /**
     * Créer un compte.
     *
     * Crée un utilisateur avec son email et son mot de passe (haché avant d'être stocké). L'inscription ne
     * connecte pas : il faut ensuite appeler `POST /api/login`.
     */
    #[Route('/api/register', name: 'api_register', methods: ['POST'])]
    #[OA\Tag(name: 'Compte')]
    #[OA\Response(
        response: 201,
        description: 'Compte créé.',
        content: new OA\JsonContent(properties: [
            new OA\Property(property: 'id', type: 'integer', example: 1),
            new OA\Property(property: 'email', type: 'string', format: 'email', example: 'claire@example.com'),
        ]),
    )]
    #[OA\Response(
        response: 409,
        description: 'Cette adresse email est déjà utilisée (erreur rattachée au champ `email`).',
        content: new OA\JsonContent(ref: '#/components/schemas/ValidationError'),
    )]
    #[OA\Response(
        response: 422,
        description: 'Email ou mot de passe invalide : chaque erreur est rattachée à son champ.',
        content: new OA\JsonContent(ref: '#/components/schemas/ValidationError'),
    )]
    public function register(
        #[MapRequestPayload] RegisterRequest $payload,
        UserPasswordHasherInterface $hasher,
        EntityManagerInterface $em,
    ): JsonResponse {
        if ($em->getRepository(User::class)->findOneBy(['email' => $payload->email])) {
            $message = 'Cette adresse email est déjà utilisée.';

            // Même format que les erreurs de validation (violations) : le frontend
            // affiche ainsi le message sous le champ email, comme pour les autres erreurs.
            return $this->json([
                'message' => $message,
                'violations' => [['propertyPath' => 'email', 'title' => $message]],
            ], Response::HTTP_CONFLICT);
        }

        $user = (new User())->setEmail($payload->email);
        $user->setPassword($hasher->hashPassword($user, $payload->password));

        $em->persist($user);
        $em->flush();

        return $this->json(['id' => $user->getId(), 'email' => $user->getEmail()], Response::HTTP_CREATED);
    }

    /**
     * Mon profil.
     *
     * Renvoie l'utilisateur correspondant au jeton JWT. Sert aussi à vérifier qu'un jeton est encore valable.
     */
    #[Route('/api/me', name: 'api_me', methods: ['GET'])]
    #[OA\Tag(name: 'Compte')]
    #[Security(name: 'Bearer')]
    #[OA\Response(response: 200, description: "L'utilisateur connecté.", content: new OA\JsonContent(ref: '#/components/schemas/User'))]
    #[OA\Response(response: 401, description: 'Jeton absent, invalide ou expiré.', content: new OA\JsonContent(ref: '#/components/schemas/AuthError'))]
    public function me(#[CurrentUser] User $user): JsonResponse
    {
        return $this->json([
            'id' => $user->getId(),
            'email' => $user->getEmail(),
            'roles' => $user->getRoles(),
        ]);
    }
}
