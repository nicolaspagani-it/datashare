<?php

namespace App\Controller\Api;

use App\Dto\UploadRequest;
use App\Entity\SharedFile;
use App\Entity\User;
use App\Repository\SharedFileRepository;
use App\Service\FileStorage;
use App\Service\ShareTokenGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Nelmio\ApiDocBundle\Attribute\Security;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * API des fichiers partagés :
 *   POST   /api/files       envoyer un fichier (public : un visiteur non connecté peut aussi envoyer)
 *   GET    /api/files       la liste de mes fichiers (connecté)
 *   DELETE /api/files/{id}  supprimer un de mes fichiers (connecté)
 */
final class FileController extends AbstractController
{
    private const TOO_BIG = 'La taille des fichiers est limitée à 1 Go';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SharedFileRepository $files,
        private readonly FileStorage $storage,
        private readonly ShareTokenGenerator $tokens,
        private readonly PasswordHasherFactoryInterface $hasherFactory,
        private readonly ValidatorInterface $validator,
        private readonly RateLimiterFactoryInterface $anonymousUploadLimiter,
        #[Autowire('%app.max_upload_size%')]
        private readonly int $maxUploadSize,
        #[Autowire('%app.front_url%')]
        private readonly string $frontUrl,
    ) {
    }

    /**
     * Envoyer un fichier.
     *
     * Route **publique** : un visiteur sans compte peut envoyer un fichier. Si un jeton JWT valide est fourni,
     * le fichier est rattaché à l'utilisateur et apparaît dans sa liste (`GET /api/files`) ; un jeton invalide
     * est refusé (401).
     *
     * La requête est de type `multipart/form-data`. Le fichier ne doit pas dépasser **1 Go** et ne doit pas
     * être vide. Le mot de passe facultatif n'est jamais stocké en clair : seul son hash l'est.
     *
     * La réponse contient le lien de partage (`url`).
     */
    #[Route('/api/files', name: 'api_files_create', methods: ['POST'])]
    #[OA\Tag(name: 'Mes fichiers')]
    #[OA\RequestBody(
        required: true,
        content: new OA\MediaType(
            mediaType: 'multipart/form-data',
            schema: new OA\Schema(
                required: ['file', 'expiration'],
                properties: [
                    new OA\Property(property: 'file', type: 'string', format: 'binary', description: 'Le fichier à partager (1 Go maximum, non vide).'),
                    new OA\Property(
                        property: 'expiration',
                        type: 'string',
                        enum: ['1', '2', '3', '4', '5', '6', '7'],
                        example: '3',
                        description: 'Durée de conservation en jours : de `1` (une journée) à `7` (une semaine).',
                    ),
                    new OA\Property(property: 'password', type: 'string', format: 'password', maxLength: 4096, description: 'Mot de passe facultatif, à saisir pour télécharger le fichier.'),
                ],
            ),
        ),
    )]
    #[OA\Response(response: 201, description: 'Fichier envoyé. La réponse contient le lien de partage.', content: new OA\JsonContent(ref: '#/components/schemas/File'))]
    #[OA\Response(response: 401, description: 'Jeton JWT fourni mais invalide ou expiré (sans jeton, l\'envoi est anonyme).', content: new OA\JsonContent(ref: '#/components/schemas/AuthError'))]
    #[OA\Response(response: 429, description: 'Visiteur non connecté : plus de 10 envois par heure depuis cette adresse IP.', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError'))]
    #[OA\Response(
        response: 422,
        description: 'Fichier absent, vide ou trop gros (champ `file`), durée invalide (champ `expiration`), mot de passe trop long (champ `password`).',
        content: new OA\JsonContent(ref: '#/components/schemas/ValidationError'),
    )]
    public function create(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        // Un visiteur anonyme est limité (10 envois par heure et par IP) : sinon n'importe qui pourrait remplir le disque.
        if ($user === null) {
            $limit = $this->anonymousUploadLimiter->create($request->getClientIp())->consume();
            if (!$limit->isAccepted()) {
                $message = 'Trop d\'envois depuis cette adresse. Connecte-toi ou réessaie plus tard.';

                return $this->json(
                    ['message' => $message, 'violations' => [['propertyPath' => 'file', 'title' => $message]]],
                    Response::HTTP_TOO_MANY_REQUESTS,
                    ['Retry-After' => (string) max(1, $limit->getRetryAfter()->getTimestamp() - time())],
                );
            }
        }

        $upload = new UploadRequest(
            expiration: $request->request->getString('expiration'),
            password: $request->request->getString('password'),
        );
        $file = $request->files->get('file');

        // On collecte toutes les erreurs, rangées par champ, au même format que le reste de l'API
        // (propertyPath = nom du champ) : le frontend les affiche au bon endroit.
        $violations = [];
        if ($message = $this->fileError($request, $file instanceof UploadedFile ? $file : null)) {
            $violations[] = ['propertyPath' => 'file', 'title' => $message];
        }
        foreach ($this->validator->validate($upload) as $violation) {
            $violations[] = ['propertyPath' => $violation->getPropertyPath(), 'title' => (string) $violation->getMessage()];
        }
        if ($violations) {
            return $this->json(['title' => 'Validation Failed', 'status' => 422, 'violations' => $violations], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        /** @var UploadedFile $file */
        // Ces informations se lisent AVANT de déplacer le fichier (après, le fichier temporaire n'existe plus).
        $size = (int) $file->getSize();
        $mimeType = $file->getMimeType() ?? 'application/octet-stream';
        $originalName = mb_substr($file->getClientOriginalName() ?: 'fichier', 0, 255);

        $shared = (new SharedFile())
            ->setOwner($user)
            ->setOriginalName($originalName)
            ->setMimeType(mb_substr($mimeType, 0, 127))
            ->setSize($size)
            ->setToken($this->uniqueToken())
            ->setExpiresAt((new \DateTimeImmutable())->modify(sprintf('+%d days', (int) $upload->expiration)));

        // Le mot de passe est optionnel ; s'il est présent, on ne garde QUE son hash.
        if ($upload->password !== '') {
            $shared->setPasswordHash($this->hasherFactory->getPasswordHasher(SharedFile::class)->hash($upload->password));
        }

        $storedName = $this->storage->store($file);
        $shared->setStoredName($storedName);

        try {
            $this->em->persist($shared);
            $this->em->flush();
        } catch (\Throwable $e) {
            $this->storage->remove($storedName); // pas de fichier orphelin sur le disque
            throw $e;
        }

        return $this->json($this->serialize($shared), Response::HTTP_CREATED);
    }

    /**
     * Lister mes fichiers.
     *
     * Les fichiers envoyés par l'utilisateur connecté, du plus récent au plus ancien, y compris ceux qui ont
     * expiré (`expired: true`, contenu supprimé du disque).
     */
    #[Route('/api/files', name: 'api_files_list', methods: ['GET'])]
    #[OA\Tag(name: 'Mes fichiers')]
    #[Security(name: 'Bearer')]
    #[OA\Response(
        response: 200,
        description: 'La liste de mes fichiers (vide si je n\'en ai pas).',
        content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: '#/components/schemas/File')),
    )]
    #[OA\Response(response: 401, description: 'Jeton absent, invalide ou expiré.', content: new OA\JsonContent(ref: '#/components/schemas/AuthError'))]
    public function list(#[CurrentUser] User $user): JsonResponse
    {
        return $this->json(array_map($this->serialize(...), $this->files->findByOwner($user)));
    }

    /**
     * Supprimer un de mes fichiers.
     *
     * Supprime la ligne et le contenu du disque : le lien de partage cesse de fonctionner. Seul le propriétaire
     * peut supprimer son fichier.
     */
    #[Route('/api/files/{id}', name: 'api_files_delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    #[OA\Tag(name: 'Mes fichiers')]
    #[Security(name: 'Bearer')]
    #[OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifiant du fichier (champ `id` de la liste).', schema: new OA\Schema(type: 'integer', example: 12))]
    #[OA\Response(response: 204, description: 'Fichier supprimé.')]
    #[OA\Response(response: 401, description: 'Jeton absent, invalide ou expiré.', content: new OA\JsonContent(ref: '#/components/schemas/AuthError'))]
    #[OA\Response(response: 404, description: 'Fichier introuvable, ou appartenant à quelqu\'un d\'autre (on ne révèle pas son existence).', content: new OA\JsonContent(ref: '#/components/schemas/Error'))]
    public function delete(int $id, #[CurrentUser] User $user): Response
    {
        $shared = $this->files->find($id);

        // 404 aussi pour le fichier d'un autre : on ne révèle pas qu'il existe.
        if (!$shared || $shared->getOwner()?->getId() !== $user->getId()) {
            return $this->json(['message' => 'Fichier introuvable.'], Response::HTTP_NOT_FOUND);
        }

        // D'abord la ligne en base, ensuite le fichier : si la base échoue, rien n'est perdu ;
        // si la suppression du fichier échoue, il ne reste qu'un fichier orphelin, sans conséquence.
        $storedName = $shared->getStoredName();
        $this->em->remove($shared);
        $this->em->flush();
        if ($storedName !== null) {
            $this->storage->remove($storedName);
        }

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    /** Message d'erreur sur le fichier envoyé, ou null s'il est correct. */
    private function fileError(Request $request, ?UploadedFile $file): ?string
    {
        if ($file === null) {
            // Quand le corps de la requête dépasse post_max_size, PHP vide $_POST et $_FILES :
            // le fichier "manque" alors à cause de sa taille.
            $bodyWasDropped = (int) $request->server->get('CONTENT_LENGTH') > 0
                && $request->files->count() === 0
                && $request->request->count() === 0;

            return $bodyWasDropped ? self::TOO_BIG : "Aucun fichier n'a été envoyé.";
        }

        if (!$file->isValid()) {
            return \in_array($file->getError(), [\UPLOAD_ERR_INI_SIZE, \UPLOAD_ERR_FORM_SIZE], true)
                ? self::TOO_BIG
                : "Le fichier n'a pas pu être envoyé. Réessaie.";
        }
        if ($file->getSize() > $this->maxUploadSize) {
            return self::TOO_BIG;
        }
        if ($file->getSize() === 0) {
            return 'Le fichier est vide.';
        }

        return null;
    }

    /** Un token qui n'existe pas déjà (les collisions sont quasi impossibles, mais on vérifie). */
    private function uniqueToken(): string
    {
        for ($attempt = 0; $attempt < 5; ++$attempt) {
            $token = $this->tokens->generate();
            if ($this->files->findOneByToken($token) === null) {
                return $token;
            }
        }

        throw new \RuntimeException('Impossible de générer un lien de partage unique.');
    }

    /** @return array<string, mixed> */
    private function serialize(SharedFile $file): array
    {
        return [
            'id' => $file->getId(),
            'token' => $file->getToken(),
            'name' => $file->getOriginalName(),
            'size' => $file->getSize(),
            'type' => $file->getMimeType(),
            'url' => rtrim($this->frontUrl, '/').'/'.$file->getToken(),
            'protected' => $file->isProtected(),
            'expired' => $file->isExpired(),
            'createdAt' => $file->getCreatedAt()->format(\DATE_ATOM),
            'expiresAt' => $file->getExpiresAt()->format(\DATE_ATOM),
        ];
    }
}
