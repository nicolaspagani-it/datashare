<?php

namespace App\Entity;

use App\Repository\SharedFileRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Un fichier partagé : ses informations en base, le contenu étant stocké sur le disque
 * (voir App\Service\FileStorage). Le lien de partage est construit à partir de "token".
 */
#[ORM\Entity(repositoryClass: SharedFileRepository::class)]
#[ORM\Table(name: 'shared_file')]
#[ORM\UniqueConstraint(name: 'UNIQ_SHARED_FILE_TOKEN', fields: ['token'])]
#[ORM\UniqueConstraint(name: 'UNIQ_SHARED_FILE_STORED_NAME', fields: ['storedName'])]
class SharedFile
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Le propriétaire, ou null pour un envoi anonyme (visiteur non connecté). */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $owner = null;

    /** Nom d'origine du fichier, tel qu'affiché à l'utilisateur. */
    #[ORM\Column(length: 255)]
    private string $originalName;

    /**
     * Nom aléatoire du fichier sur le disque. Devient null quand le contenu a été supprimé
     * (fichier expiré) : la ligne reste, pour que l'utilisateur voie "Expiré" dans sa liste.
     */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $storedName = null;

    #[ORM\Column(length: 127)]
    private string $mimeType;

    /** Taille en octets. Une colonne INTEGER suffit : la limite est de 1 Go (< 2,1 milliards). */
    #[ORM\Column(type: Types::INTEGER)]
    private int $size;

    /** Hash du mot de passe optionnel (jamais le mot de passe en clair). */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $passwordHash = null;

    /** Partie aléatoire du lien de partage, ex : "UhGyr4Kd". */
    #[ORM\Column(length: 16)]
    private string $token;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $expiresAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOwner(): ?User
    {
        return $this->owner;
    }

    public function setOwner(?User $owner): static
    {
        $this->owner = $owner;

        return $this;
    }

    public function getOriginalName(): string
    {
        return $this->originalName;
    }

    public function setOriginalName(string $originalName): static
    {
        $this->originalName = $originalName;

        return $this;
    }

    public function getStoredName(): ?string
    {
        return $this->storedName;
    }

    public function setStoredName(?string $storedName): static
    {
        $this->storedName = $storedName;

        return $this;
    }

    public function getMimeType(): string
    {
        return $this->mimeType;
    }

    public function setMimeType(string $mimeType): static
    {
        $this->mimeType = $mimeType;

        return $this;
    }

    public function getSize(): int
    {
        return $this->size;
    }

    public function setSize(int $size): static
    {
        $this->size = $size;

        return $this;
    }

    public function getPasswordHash(): ?string
    {
        return $this->passwordHash;
    }

    public function setPasswordHash(?string $passwordHash): static
    {
        $this->passwordHash = $passwordHash;

        return $this;
    }

    public function getToken(): string
    {
        return $this->token;
    }

    public function setToken(string $token): static
    {
        $this->token = $token;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(\DateTimeImmutable $expiresAt): static
    {
        $this->expiresAt = $expiresAt;

        return $this;
    }

    /** Le fichier est-il protégé par un mot de passe ? */
    public function isProtected(): bool
    {
        return $this->passwordHash !== null;
    }

    /** La date d'expiration est-elle dépassée ? */
    public function isExpired(?\DateTimeImmutable $now = null): bool
    {
        return $this->expiresAt <= ($now ?? new \DateTimeImmutable());
    }
}
