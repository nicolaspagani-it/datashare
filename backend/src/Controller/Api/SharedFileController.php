<?php

namespace App\Controller\Api;

use App\Entity\SharedFile;
use App\Repository\SharedFileRepository;
use App\Service\DownloadTicket;
use App\Service\FileStorage;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Récupération d'un fichier partagé À PARTIR DE SON LIEN. Toutes ces routes sont publiques :
 * n'importe qui possédant le lien (le "token") peut y accéder, sans compte.
 *
 *   GET  /api/shared/{token}           informations sur le fichier (nom, taille, protégé ou non...)
 *   POST /api/shared/{token}/unlock    vérifie le mot de passe, renvoie l'URL de téléchargement
 *   GET  /api/shared/{token}/download  télécharge le fichier
 *
 * Réponses d'erreur :
 *   404 le lien n'existe pas
 *   410 le fichier a expiré (ou son contenu a été supprimé)
 *   403 mot de passe incorrect / ticket absent ou invalide
 *   422 mot de passe non fourni
 *   429 trop d'essais de mot de passe
 */
final class SharedFileController extends AbstractController
{
    private const TOKEN = '[A-Za-z0-9]{1,16}';

    public function __construct(
        private readonly SharedFileRepository $files,
        private readonly FileStorage $storage,
        private readonly DownloadTicket $tickets,
        private readonly PasswordHasherFactoryInterface $hasherFactory,
        private readonly RateLimiterFactoryInterface $fileUnlockLimiter,
    ) {
    }

    #[Route('/api/shared/{token}', name: 'api_shared_show', methods: ['GET'], requirements: ['token' => self::TOKEN])]
    public function show(string $token): JsonResponse
    {
        $file = $this->findAvailable($token);
        if ($file instanceof JsonResponse) {
            return $file;
        }

        // Uniquement ce qui sert à afficher la page de téléchargement : jamais le propriétaire,
        // le nom stocké sur le disque ni le hash du mot de passe.
        return $this->json([
            'token' => $file->getToken(),
            'name' => $file->getOriginalName(),
            'size' => $file->getSize(),
            'type' => $file->getMimeType(),
            'protected' => $file->isProtected(),
            'expiresAt' => $file->getExpiresAt()->format(\DATE_ATOM),
        ]);
    }

    #[Route('/api/shared/{token}/unlock', name: 'api_shared_unlock', methods: ['POST'], requirements: ['token' => self::TOKEN])]
    public function unlock(string $token, Request $request): JsonResponse
    {
        $file = $this->findAvailable($token);
        if ($file instanceof JsonResponse) {
            return $file;
        }

        // Fichier sans mot de passe : rien à vérifier, on donne directement l'URL.
        if (!$file->isProtected()) {
            return $this->json(['downloadUrl' => $this->downloadPath($file)]);
        }

        $password = $request->getPayload()->getString('password');
        if ($password === '') {
            return $this->passwordError('Le mot de passe est obligatoire.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Limite les essais pour empêcher de deviner le mot de passe par force brute.
        $limiter = $this->fileUnlockLimiter->create($token.'|'.$request->getClientIp());
        $limit = $limiter->consume();
        if (!$limit->isAccepted()) {
            $minutes = max(1, (int) ceil(($limit->getRetryAfter()->getTimestamp() - time()) / 60));
            $response = $this->passwordError(
                sprintf('Trop de tentatives. Réessaie dans %d minute%s.', $minutes, $minutes > 1 ? 's' : ''),
                Response::HTTP_TOO_MANY_REQUESTS,
            );
            $response->headers->set('Retry-After', (string) max(1, $limit->getRetryAfter()->getTimestamp() - time()));

            return $response;
        }

        $hasher = $this->hasherFactory->getPasswordHasher(SharedFile::class);
        if (!$hasher->verify((string) $file->getPasswordHash(), $password)) {
            return $this->passwordError('Mot de passe incorrect.', Response::HTTP_FORBIDDEN);
        }

        $limiter->reset(); // un essai réussi remet le compteur à zéro

        return $this->json(['downloadUrl' => $this->downloadPath($file, $this->tickets->issue($file))]);
    }

    #[Route('/api/shared/{token}/download', name: 'api_shared_download', methods: ['GET'], requirements: ['token' => self::TOKEN])]
    public function download(string $token, Request $request): Response
    {
        $file = $this->findAvailable($token);
        if ($file instanceof JsonResponse) {
            return $file;
        }

        // Fichier protégé : il faut le ticket obtenu en donnant le bon mot de passe (voir unlock()).
        if ($file->isProtected() && !$this->tickets->isValid($file, $request->query->getString('ticket'))) {
            return $this->passwordError('Un mot de passe est nécessaire pour télécharger ce fichier.', Response::HTTP_FORBIDDEN);
        }

        // BinaryFileResponse envoie le fichier en flux (sans le charger en mémoire : 1 Go possible)
        // et gère la reprise d'un téléchargement interrompu (en-tête Range).
        $response = new BinaryFileResponse($this->storage->path($file->getStoredName()));
        $response->headers->set('Content-Type', $file->getMimeType());
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT, // toujours un téléchargement, jamais affiché dans le navigateur
            $file->getOriginalName(),
            $this->asciiFileName($file->getOriginalName()),
        );
        $response->headers->set('X-Content-Type-Options', 'nosniff'); // le navigateur n'interprète pas le contenu
        $response->headers->set('Cache-Control', 'private, no-store'); // pas de copie en cache partagé

        return $response;
    }

    /**
     * Le fichier correspondant au lien, s'il est disponible, sinon la réponse d'erreur à renvoyer.
     */
    private function findAvailable(string $token): SharedFile|JsonResponse
    {
        $file = $this->files->findOneByToken($token);
        if ($file === null) {
            return $this->json(['message' => "Ce lien n'existe pas."], Response::HTTP_NOT_FOUND);
        }

        // Expiré, ou contenu déjà supprimé du disque (par la commande de purge, ou à la main).
        if ($file->isExpired() || $file->getStoredName() === null || !$this->storage->exists($file->getStoredName())) {
            return $this->json(['message' => "Ce fichier a expiré, il n'est plus stocké chez nous."], Response::HTTP_GONE);
        }

        return $file;
    }

    private function downloadPath(SharedFile $file, ?string $ticket = null): string
    {
        $path = '/api/shared/'.$file->getToken().'/download';

        return $ticket === null ? $path : $path.'?ticket='.$ticket;
    }

    /** Erreur sur le champ "password", au même format que les erreurs de validation du reste de l'API. */
    private function passwordError(string $message, int $status): JsonResponse
    {
        return $this->json([
            'message' => $message,
            'violations' => [['propertyPath' => 'password', 'title' => $message]],
        ], $status);
    }

    /**
     * Version du nom de fichier uniquement en ASCII, pour les anciens navigateurs
     * (le nom exact, accents compris, est envoyé à part par setContentDisposition()).
     */
    private function asciiFileName(string $name): string
    {
        // Le modificateur /u traite le nom comme de l'UTF-8 : un "é" devient UN "_" (et non un par octet).
        $ascii = preg_replace('/[^\x20-\x7e]|[%\/\\\\]/u', '_', $name);

        return trim((string) $ascii) !== '' ? $ascii : 'fichier';
    }
}
