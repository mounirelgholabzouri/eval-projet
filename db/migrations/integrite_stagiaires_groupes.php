<?php
/**
 * Migration : intégrité stagiaires / groupes (ménage 2026-11-16)
 *  S1. FK sessions_eval.stagiaire_id → stagiaires(id) ON DELETE RESTRICT
 *      (supprimerStagiaire() supprime déjà réponses puis sessions avant le stagiaire)
 *  S2. FK stagiaires.groupe_id → groupes(id) : CASCADE → RESTRICT
 *      (supprimer un groupe ne doit plus effacer ses stagiaires en silence)
 *  S3. UNIQUE groupes.nom (collation _ci : « ID101 » = « id101 »)
 *  S5. stagiaires.annee_scolaire : suppression du défaut figé '2025-2026'
 *
 * Idempotente. Chaque étape est sautée (avec message) si les données ne la permettent pas.
 */
require_once __DIR__ . '/../../config/database.php';

$pdo = getDB();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

/** Règle de suppression d'une FK (ou null si absente). */
function fkDeleteRule(PDO $pdo, string $table, string $column): ?array {
    $st = $pdo->prepare("SELECT k.CONSTRAINT_NAME, r.DELETE_RULE
        FROM information_schema.KEY_COLUMN_USAGE k
        JOIN information_schema.REFERENTIAL_CONSTRAINTS r
          ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME
        WHERE k.TABLE_SCHEMA = DATABASE() AND k.TABLE_NAME = ? AND k.COLUMN_NAME = ?
          AND k.REFERENCED_TABLE_NAME IS NOT NULL LIMIT 1");
    $st->execute([$table, $column]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

// ── S1 ──────────────────────────────────────────────────────────
if (fkDeleteRule($pdo, 'sessions_eval', 'stagiaire_id')) {
    echo "S1 déjà appliquée.\n";
} else {
    $orph = (int)$pdo->query("SELECT COUNT(*) FROM sessions_eval se
        WHERE se.stagiaire_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM stagiaires s WHERE s.id = se.stagiaire_id)")->fetchColumn();
    if ($orph > 0) {
        echo "S1 SAUTÉE : $orph session(s) pointent vers un stagiaire inexistant — à nettoyer d'abord.\n";
    } else {
        $pdo->exec("ALTER TABLE sessions_eval ADD CONSTRAINT fk_session_stagiaire
                    FOREIGN KEY (stagiaire_id) REFERENCES stagiaires(id) ON DELETE RESTRICT");
        echo "S1 OK : FK sessions_eval.stagiaire_id (RESTRICT).\n";
    }
}

// ── S2 ──────────────────────────────────────────────────────────
$fk = fkDeleteRule($pdo, 'stagiaires', 'groupe_id');
if ($fk && $fk['DELETE_RULE'] !== 'CASCADE') {
    echo "S2 déjà appliquée ({$fk['DELETE_RULE']}).\n";
} else {
    if ($fk) $pdo->exec("ALTER TABLE stagiaires DROP FOREIGN KEY `{$fk['CONSTRAINT_NAME']}`");
    $pdo->exec("ALTER TABLE stagiaires ADD CONSTRAINT fk_stagiaire_groupe
                FOREIGN KEY (groupe_id) REFERENCES groupes(id) ON DELETE RESTRICT");
    echo "S2 OK : FK stagiaires.groupe_id en RESTRICT.\n";
}

// ── S3 ──────────────────────────────────────────────────────────
$idx = $pdo->query("SHOW INDEX FROM groupes WHERE Key_name = 'uq_groupe_nom'")->fetchAll();
if ($idx) {
    echo "S3 déjà appliquée.\n";
} else {
    $dups = $pdo->query("SELECT GROUP_CONCAT(nom SEPARATOR ', ') FROM groupes GROUP BY nom HAVING COUNT(*) > 1")->fetchAll(PDO::FETCH_COLUMN);
    if ($dups) {
        echo "S3 SAUTÉE : noms de groupe en double (" . implode(' | ', $dups) . ") — fusionner d'abord.\n";
    } else {
        $pdo->exec("ALTER TABLE groupes ADD UNIQUE KEY uq_groupe_nom (nom)");
        echo "S3 OK : UNIQUE groupes.nom.\n";
    }
}

// ── S5 ──────────────────────────────────────────────────────────
$col = $pdo->query("SHOW COLUMNS FROM stagiaires LIKE 'annee_scolaire'")->fetch(PDO::FETCH_ASSOC);
if ($col && $col['Default'] === null) {
    echo "S5 déjà appliquée.\n";
} elseif ($col) {
    $pdo->exec("ALTER TABLE stagiaires MODIFY annee_scolaire VARCHAR(20) COLLATE utf8mb4_unicode_ci NOT NULL");
    echo "S5 OK : plus de valeur par défaut pour annee_scolaire.\n";
}
