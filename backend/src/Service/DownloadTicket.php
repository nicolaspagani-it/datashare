<?php

namespace App\Service;

use App\Entity\SharedFile;

/**
 * Ticket de téléchargement d'un fichier protégé par mot de passe.
 *
 * Pourquoi un ticket : le navigateur télécharge un fichier avec une simple URL (GET), qui ne peut
 * pas porter le mot de passe (il resterait dans l'historique et les journaux). Le parcours est donc :
 *   1. POST /api/shared/{token}/unlock avec le mot de passe  ->  le serveur le vérifie
 *   2. il renvoie une URL contenant un ticket valable quelques minutes
 *   3. le navigateur ouvre cette URL et télécharge le fichier directement (en flux, sans le charger en mémoire)
 *
 * Le ticket est signé (HMAC-SHA256) : impossible de le fabriquer ou de le modifier sans connaître la clé.
 * La clé est le hash du mot de passe du fichier : un secret qui n'existe que sur le serveur, propre à chaque
 * fichier. Un ticket ne vaut donc que pour UN fichier.
 *
 * Format : "<timestamp d'expiration>.<signature>"
 */
class DownloadTicket
{
    /** Durée de validité d'un ticket, en secondes (10 minutes). */
    public const TTL = 600;

    public function issue(SharedFile $file, ?\DateTimeImmutable $now = null): string
    {
        $expires = ($now ?? new \DateTimeImmutable())->getTimestamp() + self::TTL;

        return $expires.'.'.$this->signature($file, $expires);
    }

    public function isValid(SharedFile $file, string $ticket, ?\DateTimeImmutable $now = null): bool
    {
        // Un fichier sans mot de passe n'a pas de clé de signature : aucun ticket ne peut être valable.
        if (!$file->isProtected() || !preg_match('/^(\d{1,12})\.([a-f0-9]{64})$/', $ticket, $parts)) {
            return false;
        }

        [, $expires, $signature] = $parts;
        if ((int) $expires < ($now ?? new \DateTimeImmutable())->getTimestamp()) {
            return false; // ticket périmé
        }

        // hash_equals : comparaison en temps constant (évite de deviner la signature par mesure de durée)
        return hash_equals($this->signature($file, (int) $expires), $signature);
    }

    private function signature(SharedFile $file, int $expires): string
    {
        return hash_hmac('sha256', $file->getToken().'|'.$expires, (string) $file->getPasswordHash());
    }
}
