<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Stocke les fichiers envoyés sur le disque, HORS du dossier public/ : ils ne sont
 * jamais accessibles directement par une URL, seulement via l'API.
 * Chaque fichier reçoit un nom aléatoire : le nom d'origine n'est gardé qu'en base.
 */
class FileStorage
{
    public function __construct(
        #[Autowire('%app.upload_dir%')]
        private readonly string $directory,
    ) {
    }

    /** Déplace le fichier envoyé dans le dossier de stockage et renvoie son nom sur le disque. */
    public function store(UploadedFile $file): string
    {
        $name = bin2hex(random_bytes(16));

        if (!is_dir($this->directory)) {
            mkdir($this->directory, 0775, true);
        }
        $file->move($this->directory, $name);

        return $name;
    }

    public function path(string $storedName): string
    {
        // basename() : on ne sort jamais du dossier de stockage, même avec un nom piégé ("../...")
        return $this->directory.DIRECTORY_SEPARATOR.basename($storedName);
    }

    public function exists(string $storedName): bool
    {
        return is_file($this->path($storedName));
    }

    /** Supprime le fichier du disque (sans erreur s'il n'existe déjà plus). */
    public function remove(string $storedName): void
    {
        $path = $this->path($storedName);
        if (is_file($path)) {
            unlink($path);
        }
    }
}
