<?php

/**
 * Vérifie qu'on peut se connecter à PostgreSQL avec les identifiants donnés, et affiche la
 * version majeure du serveur (ex : "18"). Utilisé par scripts/install.ps1 et scripts/install.sh.
 *
 * Usage : DB_PASSWORD=... php scripts/db/check-connection.php <hôte> <port> <utilisateur>
 *
 * Le mot de passe est lu dans la variable d'environnement DB_PASSWORD (et non en argument) pour
 * ne pas apparaître dans la liste des processus.
 *
 * Code de sortie : 0 = connexion réussie, 1 = échec (le message d'erreur est écrit sur STDERR).
 */

[, $host, $port, $user] = $argv + [null, '127.0.0.1', '5432', 'postgres'];
$password = getenv('DB_PASSWORD') ?: '';

if (!extension_loaded('pdo_pgsql')) {
    fwrite(STDERR, "L'extension PHP pdo_pgsql n'est pas activée (php.ini : extension=pdo_pgsql).\n");
    exit(1);
}

try {
    // On se connecte à la base "postgres", qui existe sur toute installation PostgreSQL.
    $pdo = new PDO(
        sprintf('pgsql:host=%s;port=%s;dbname=postgres', $host, $port),
        $user,
        $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5],
    );
    echo intdiv((int) $pdo->query('SHOW server_version_num')->fetchColumn(), 10000);
    exit(0);
} catch (PDOException $e) {
    fwrite(STDERR, $e->getMessage()."\n");
    exit(1);
}
