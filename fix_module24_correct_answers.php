<?php
/**
 * Correction des bonnes réponses du module 24 (M205-EFM)
 * Toutes les choix_reponses.is_correct étaient à 0 — données importées sans marquage.
 */
require_once __DIR__ . '/includes/functions.php';

$pdo = getDB();
$pdo->beginTransaction();

try {
    // ────────────────────────────────────────────────────────
    // 1. Bonnes réponses identifiées par leur choix_id
    // ────────────────────────────────────────────────────────
    $correctChoixIds = [
        4224, // Q1189 : SaaS → Le fournisseur Cloud gère les applications
        4228, // Q1190 : Vendor lock-in → dépendance excessive à un seul fournisseur
        4232, // Q1191 : CSPM = Cloud Security Posture Management
        4237, // Q1192 : secrets/clés/certificats → Azure Key Vault
        4240, // Q1193 : JIT Access → ferme les ports par défaut, ouvre temporairement
        4245, // Q1194 : ADE Windows → BitLocker
        4248, // Q1195 : NSG → ACL filtrant trafic selon IP, port, protocole
        4252, // Q1196 : NSG vs Firewall → Azure Firewall prend en charge FQDN + règles appli
        4256, // Q1197 : FQDN = Fully Qualified Domain Name
        4261, // Q1198 : RDP/SSH navigateur sans port public → Azure Bastion
        4264, // Q1199 : auth vs autorisation → auth=vérifier identité, autorisation=droits
        4269, // Q1200 : RBAC lecture seule → Reader
        4272, // Q1201 : OTP → code usage unique 30-60 secondes
        4276, // Q1202 : SAS Token → URL signée cryptographiquement avec accès limité
        4281, // Q1203 : Log Analytics → KQL (Kusto Query Language)
        4285, // Q1204 : Activity Log rétention → 90 jours par défaut
        4293, // Q1206 : Azure Arc → étend gouvernance Azure aux environnements multi-cloud
        4295, // Q1207 : SaaS client responsable données/identités → Vrai
        4298, // Q1208 : CWPP surveille config et génère score → Faux (c'est CSPM, pas CWPP)
        4300, // Q1209 : ADE BitLocker pour Linux → Faux (BitLocker=Windows, DM-Crypt=Linux)
        4302, // Q1210 : stocker secrets dans code source → Faux
        4303, // Q1211 : NSG priorité faible évaluée en premier → Vrai
        4306, // Q1212 : Bastion expose port 3389 Internet → Faux (Bastion évite l'exposition)
        4307, // Q1213 : Secure Score élevé = bien configuré → Vrai
        4310, // Q1214 : Sentinel SOAR uniquement → Faux (c'est SIEM + SOAR)
        4311, // Q1215 : Activity Log enregistre opérations gestion → Vrai
        4314, // Q1216 : Azure Policy et RBAC jouent le même rôle → Faux
    ];

    $placeholders = implode(',', array_fill(0, count($correctChoixIds), '?'));
    $updated = $pdo->prepare("UPDATE choix_reponses SET is_correct=1 WHERE id IN ($placeholders)");
    $updated->execute($correctChoixIds);
    $nbChoix = $updated->rowCount();
    echo "✓ $nbChoix choix marqués is_correct=1\n";

    // ────────────────────────────────────────────────────────
    // 2. Recalculer reponses_stagiaires pour les sessions module 24
    // ────────────────────────────────────────────────────────
    $sessions = $pdo->prepare("
        SELECT s.id, s.total_points
        FROM sessions_eval s
        WHERE s.module_id = 24 AND s.statut = 'termine'
    ");
    $sessions->execute();
    $sessionsList = $sessions->fetchAll();
    echo "→ " . count($sessionsList) . " sessions à recalculer\n";

    $updateRep = $pdo->prepare("
        UPDATE reponses_stagiaires rs
        JOIN choix_reponses cr ON cr.id = rs.choix_id
        JOIN questions q ON q.id = rs.question_id
        SET rs.is_correct = cr.is_correct,
            rs.points_obtenus = CASE WHEN cr.is_correct=1 THEN q.points ELSE 0 END
        WHERE rs.session_id = ?
    ");

    $updateSess = $pdo->prepare("
        UPDATE sessions_eval s
        SET s.score = (
                SELECT COALESCE(SUM(rs.points_obtenus), 0)
                FROM reponses_stagiaires rs
                WHERE rs.session_id = s.id
            ),
            s.pourcentage = CASE
                WHEN s.total_points > 0
                THEN ROUND(
                    (SELECT COALESCE(SUM(rs.points_obtenus), 0)
                     FROM reponses_stagiaires rs
                     WHERE rs.session_id = s.id)
                    / s.total_points * 100, 2)
                ELSE 0
            END
        WHERE s.id = ?
    ");

    foreach ($sessionsList as $sess) {
        $updateRep->execute([$sess['id']]);
        $updateSess->execute([$sess['id']]);
    }

    // ────────────────────────────────────────────────────────
    // 3. Afficher le résultat
    // ────────────────────────────────────────────────────────
    $check = $pdo->prepare("
        SELECT s.id, s.nom, s.prenom, s.score, s.total_points, s.pourcentage
        FROM sessions_eval s
        WHERE s.module_id = 24 AND s.statut = 'termine'
        ORDER BY s.pourcentage DESC
    ");
    $check->execute();
    echo "\nRécapitulatif des sessions recalculées :\n";
    echo str_pad('ID', 6) . str_pad('Nom', 20) . str_pad('Prénom', 20) . str_pad('Score', 12) . "Pct\n";
    echo str_repeat('-', 65) . "\n";
    foreach ($check->fetchAll() as $r) {
        echo str_pad($r['id'], 6)
            . str_pad($r['nom'] ?? '—', 20)
            . str_pad($r['prenom'] ?? '—', 20)
            . str_pad($r['score'] . '/' . $r['total_points'], 12)
            . $r['pourcentage'] . "%\n";
    }

    // ────────────────────────────────────────────────────────
    // 4. Questions sans choix (1187, 1188, 1205) — signalement
    // ────────────────────────────────────────────────────────
    $noChoix = $pdo->query("
        SELECT q.id, LEFT(q.texte,70) AS texte
        FROM questions q
        WHERE q.module_id=24
          AND NOT EXISTS (SELECT 1 FROM choix_reponses cr WHERE cr.question_id=q.id)
    ")->fetchAll();
    if ($noChoix) {
        echo "\n⚠ Questions SANS choix (non corrigeables automatiquement) :\n";
        foreach ($noChoix as $q) {
            echo "  - Q{$q['id']}: {$q['texte']}\n";
        }
        echo "  → Ces questions nécessitent un ajout manuel des choix dans l'admin.\n";
    }

    $pdo->commit();
    echo "\n✅ Correction terminée avec succès.\n";

} catch (Throwable $e) {
    $pdo->rollBack();
    echo "❌ Erreur : " . $e->getMessage() . "\n";
}
