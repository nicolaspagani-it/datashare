<?php

namespace App\Controller\Api;

use App\Dto\UploadRequest;
use App\Entity\SharedFile;
use App\Entity\User;
use App\Repository\SharedFileRepository;
use App\Service\FileStorage;
use App\Service\ShareTokenGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;
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
        #[Autowire('%app.max_upload_size%')]
        private readonly int $maxUploadSize,
        #[Autowire('%app.front_url%')]
        private readonly string $frontUrl,
    ) {
    }

    #[Route('/api/files', name: 'api_files_create', methods: ['POST'])]
    public function create(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
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

    #[Route('/api/files', name: 'api_files_list', methods: ['GET'])]
    public function list(#[CurrentUser] User $user): JsonResponse
    {
        return $this->json(array_map($this->serialize(...), $this->files->findByOwner($user)));
    }

    #[Route('/api/files/{id}', name: 'api_files_delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function delete(int $id, #[CurrentUser] User $user): Response
    {
        $shared = $this->files->find($id);

        // 404 aussi pour le fichier d'un autre : on ne révèle pas qu'il existe.
        if (!$shared || $shared->getOwner()?->getId() !== $user->getId()) {
            return $this->json(['message' => 'Fichier introuvable.'], Response::HTTP_NOT_FOUND);
        }

        if ($shared->getStoredName() !== null) {
            $this->storage->remove($shared->getStoredName());
        }
        $this->em->remove($shared);
        $this->em->flush();

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
