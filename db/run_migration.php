<?php
/**
 * Script de migration : Supprimer les parties
 * Exécute : db/migrations/001_remove_parties.sql
 */

require_once __DIR__ . '/../config/database.php';

$pdo = getDB();

try {
    $pdo->beginTransaction();

    echo "⏳ Suppression du système de parties...\n";

    // Désactiver les FK temporairement
    $pdo->exec("SET FOREIGN_KEY_CHECKS=0");

    // Supprimer les contraintes FK
    $pdo->exec("ALTER TABLE `questions` DROP FOREIGN KEY `fk_question_partie`");
    echo "✓ Contrainte FK supprimée\n";

    // Supprimer la colonne partie_id
    $pdo->exec("ALTER TABLE `questions` DROP COLUMN `partie_id`");
    echo "✓ Colonne partie_id supprimée\n";

    // Supprimer la table parties
    $pdo->exec("DROP TABLE IF EXISTS `parties`");
    echo "✓ Table parties supprimée\n";

    // Réactiver les FK
    $pdo->exec("SET FOREIGN_KEY_CHECKS=1");

    $pdo->commit();

    echo "\n✅ Migration complétée avec succès !\n";
    echo "   Les questions ont été conservées\n";
    echo "   Le système de parties a été supprimé\n";

} catch (Exception $e) {
    $pdo->rollBack();
    echo "\n❌ Erreur lors de la migration :\n";
    echo "   " . $e->getMessage() . "\n";
    exit(1);
}
