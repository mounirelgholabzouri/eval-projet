<?php
/**
 * Migration : ajoute le type 'glisser_deposer' à questions.type (aligné sur db/schema.sql).
 * Utile sur les bases où add_opk_flavor_q11_glisser.php n'a pas tourné (cette dernière
 * dépend de la table `parties`, supprimée par la refonte CC/EFM).
 *
 * Idempotente.
 */
require_once __DIR__ . '/../../config/database.php';

$pdo = getDB();
$col = $pdo->query("SHOW COLUMNS FROM questions LIKE 'type'")->fetch(PDO::FETCH_ASSOC);

if (strpos($col['Type'], "'glisser_deposer'") !== false) {
    echo "Déjà appliqué : questions.type accepte 'glisser_deposer'.\n";
    exit(0);
}

$pdo->exec("ALTER TABLE questions MODIFY type
            ENUM('qcm','vrai_faux','texte_libre','multiple','glisser_deposer') NOT NULL DEFAULT 'qcm'");
echo "Type 'glisser_deposer' ajouté à questions.type.\n";
