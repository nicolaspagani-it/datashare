<?php

namespace App\Command;

use App\Repository\SharedFileRepository;
use App\Service\FileStorage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Supprime du disque le contenu des fichiers expirés.
 * La ligne reste en base (storedName passe à null) : l'utilisateur continue de voir
 * le fichier "Expiré" dans sa liste, mais il n'est plus stocké chez nous.
 *
 * À lancer régulièrement (tâche planifiée), par exemple toutes les heures :
 *   php bin/console app:files:purge-expired
 */
#[AsCommand(name: 'app:files:purge-expired', description: 'Supprime du disque les fichiers dont la date d\'expiration est dépassée')]
final class PurgeExpiredFilesCommand extends Command
{
    public function __construct(
        private readonly SharedFileRepository $files,
        private readonly FileStorage $storage,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $expired = $this->files->findExpiredWithContent(new \DateTimeImmutable());
        foreach ($expired as $file) {
            $this->storage->remove($file->getStoredName());
            $file->setStoredName(null);
        }
        $this->em->flush();

        $io->success(sprintf('%d fichier(s) expiré(s) supprimé(s) du disque.', \count($expired)));

        return Command::SUCCESS;
    }
}
