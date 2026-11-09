<?php
/**
 * Migration : Module OPK-FLAVOR — 10 questions contrôle continu
 * Thème : OpenStack Flavor — généralités + relation avec les images
 * Mix QCM direct (6) + Vrai/Faux (4)
 */

require_once __DIR__ . '/../../config/database.php';
$pdo = getDB();

$marker = __DIR__ . '/../.done_import_opk_flavor';
if (file_exists($marker)) {
    echo "Déjà exécuté.\n";
    exit(0);
}

$pdo->beginTransaction();

try {
    // ── Module ──────────────────────────────────────────────────────────────
    $stmt = $pdo->prepare("SELECT id FROM modules WHERE nom = ?");
    $stmt->execute(['OPK-FLAVOR']);
    $moduleId = $stmt->fetchColumn();

    if (!$moduleId) {
        $pdo->prepare("INSERT INTO modules (nom, description, actif, nb_questions_controle)
                        VALUES (?, ?, 1, 10)")
            ->execute([
                'OPK-FLAVOR',
                'OpenStack Flavor — Généralités et relation avec les images — Contrôle continu (10 questions)',
            ]);
        $moduleId = $pdo->lastInsertId();
        echo "Module OPK-FLAVOR créé (id=$moduleId)\n";
    } else {
        echo "Module OPK-FLAVOR existant (id=$moduleId)\n";
    }

    // ── Partie par défaut ────────────────────────────────────────────────────
    $stmt = $pdo->prepare("SELECT id FROM parties WHERE module_id = ? AND nom = 'Général'");
    $stmt->execute([$moduleId]);
    $partieId = $stmt->fetchColumn();

    if (!$partieId) {
        $pdo->prepare("INSERT INTO parties (module_id, nom, ordre, actif) VALUES (?, 'Général', 1, 1)")
            ->execute([$moduleId]);
        $partieId = $pdo->lastInsertId();
        echo "Partie 'Général' créée (id=$partieId)\n";
    }

    // ── Questions ────────────────────────────────────────────────────────────
    // is_correct : 1 = bonne réponse / 0 = mauvaise
    // Pour vrai_faux : choix 'Vrai' et 'Faux'

    $questions = [

        // ── QCM 1 — Définition d'un flavor ───────────────────────────────────
        [
            'texte'  => "Qu'est-ce qu'un flavor dans OpenStack ?",
            'type'   => 'qcm',
            'points' => 1.00,
            'choix'  => [
                "Un modèle d'image disque préconfigurée (OS + logiciels)"                 => 0,
                "Un gabarit qui définit les ressources allouées à une instance (vCPU, RAM, disque)" => 1,
                "Un type de réseau virtuel partagé entre projets"                          => 0,
                "Un profil de sécurité associé à un groupe d'instances"                   => 0,
            ],
        ],

        // ── QCM 2 — Composantes d'un flavor ──────────────────────────────────
        [
            'texte'  => "Quels sont les trois attributs de base toujours présents dans un flavor OpenStack ?",
            'type'   => 'qcm',
            'points' => 1.00,
            'choix'  => [
                "vCPU, RAM, disque racine (root disk)"                            => 1,
                "vCPU, bande passante réseau, quota de snapshots"                 => 0,
                "RAM, adresse IP flottante, groupe de sécurité"                   => 0,
                "disque racine, disque éphémère, volume Cinder"                   => 0,
            ],
        ],

        // ── QCM 3 — Disque éphémère ───────────────────────────────────────────
        [
            'texte'  => "Que se passe-t-il aux données stockées sur le disque éphémère d'une instance OpenStack lorsque cette instance est supprimée ?",
            'type'   => 'qcm',
            'points' => 1.00,
            'choix'  => [
                "Les données sont conservées dans un snapshot automatique"       => 0,
                "Les données sont transférées vers un volume Cinder"             => 0,
                "Les données sont définitivement perdues"                        => 1,
                "Les données restent disponibles 7 jours puis sont supprimées"   => 0,
            ],
        ],

        // ── QCM 4 — Flavor public vs privé ───────────────────────────────────
        [
            'texte'  => "Quelle commande OpenStack CLI permet de créer un flavor visible par tous les projets (public) ?",
            'type'   => 'qcm',
            'points' => 1.00,
            'choix'  => [
                "openstack flavor create --public <nom> --vcpus <n> --ram <mo> --disk <go>"  => 1,
                "openstack flavor create --shared <nom> --vcpus <n> --ram <mo> --disk <go>"  => 0,
                "openstack flavor create --global <nom> --vcpus <n> --ram <mo> --disk <go>"  => 0,
                "openstack flavor create --all-projects <nom> --vcpus <n> --ram <mo> --disk <go>" => 0,
            ],
        ],

        // ── QCM 5 — Relation flavor / image (min_disk) ───────────────────────
        [
            'texte'  => "Un flavor définit un disque racine de 10 Go. L'image Ubuntu choisie possède la propriété min_disk=20. Que se passe-t-il au lancement de l'instance ?",
            'type'   => 'qcm',
            'points' => 1.00,
            'choix'  => [
                "L'instance démarre normalement, l'image s'ajuste au disque du flavor"              => 0,
                "Le lancement échoue car le disque du flavor est inférieur à min_disk de l'image"   => 1,
                "OpenStack crée automatiquement un volume Cinder de 20 Go pour compenser"           => 0,
                "L'image est tronquée à 10 Go pour correspondre au flavor"                          => 0,
            ],
        ],

        // ── QCM 6 — extra_specs ───────────────────────────────────────────────
        [
            'texte'  => "À quoi servent les extra_specs d'un flavor OpenStack ?",
            'type'   => 'qcm',
            'points' => 1.00,
            'choix'  => [
                "Définir des métadonnées supplémentaires sur une image Glance"                       => 0,
                "Contraindre le placement des instances sur des hyperviseurs ayant des capacités spécifiques" => 1,
                "Augmenter dynamiquement le nombre de vCPU pendant l'exécution de l'instance"       => 0,
                "Chiffrer automatiquement le disque racine de l'instance"                            => 0,
            ],
        ],

        // ── V/F 7 ─────────────────────────────────────────────────────────────
        [
            'texte'  => "Un flavor avec disque racine = 0 Go signifie que l'instance démarrera obligatoirement depuis un volume Cinder persistent.",
            'type'   => 'vrai_faux',
            'points' => 1.00,
            'choix'  => [
                "Vrai" => 1,
                "Faux" => 0,
            ],
        ],

        // ── V/F 8 ─────────────────────────────────────────────────────────────
        [
            'texte'  => "La propriété min_ram d'une image Glance est vérifiée par Nova au moment du lancement d'une instance : si la RAM du flavor est inférieure à min_ram, le démarrage est refusé.",
            'type'   => 'vrai_faux',
            'points' => 1.00,
            'choix'  => [
                "Vrai" => 1,
                "Faux" => 0,
            ],
        ],

        // ── V/F 9 ─────────────────────────────────────────────────────────────
        [
            'texte'  => "Un administrateur peut modifier librement les attributs (vCPU, RAM, disque) d'un flavor existant déjà utilisé par des instances en cours d'exécution.",
            'type'   => 'vrai_faux',
            'points' => 1.00,
            'choix'  => [
                "Vrai" => 0,
                "Faux" => 1,
            ],
        ],

        // ── V/F 10 ────────────────────────────────────────────────────────────
        [
            'texte'  => "Avec un flavor ayant un disque de swap défini, OpenStack crée automatiquement une partition swap sur l'hôte hyperviseur lors du démarrage de l'instance.",
            'type'   => 'vrai_faux',
            'points' => 1.00,
            'choix'  => [
                "Vrai" => 1,
                "Faux" => 0,
            ],
        ],
    ];

    $insQ = $pdo->prepare("INSERT INTO questions (module_id, partie_id, texte, type, points, ordre)
                            VALUES (?, ?, ?, ?, ?, ?)");
    $insC = $pdo->prepare("INSERT INTO choix_reponses (question_id, texte, is_correct, ordre)
                            VALUES (?, ?, ?, ?)");

    // Nettoyer les questions existantes du module pour éviter les doublons
    $existingQ = $pdo->prepare("SELECT id FROM questions WHERE module_id = ?");
    $existingQ->execute([$moduleId]);
    $existingIds = $existingQ->fetchAll(PDO::FETCH_COLUMN);
    if ($existingIds) {
        $placeholders = implode(',', array_fill(0, count($existingIds), '?'));
        $pdo->prepare("DELETE FROM choix_reponses WHERE question_id IN ($placeholders)")
            ->execute($existingIds);
        $pdo->prepare("DELETE FROM questions WHERE module_id = ?")
            ->execute([$moduleId]);
        echo count($existingIds) . " questions existantes supprimées.\n";
    }

    foreach ($questions as $ordre => $q) {
        $insQ->execute([$moduleId, $partieId, $q['texte'], $q['type'], $q['points'], $ordre + 1]);
        $qId = $pdo->lastInsertId();

        $cOrdre = 1;
        foreach ($q['choix'] as $texte => $isCorrect) {
            $insC->execute([$qId, $texte, $isCorrect, $cOrdre++]);
        }
    }

    echo count($questions) . " questions injectées avec succès.\n";

    $pdo->commit();
    file_put_contents($marker, date('Y-m-d H:i:s'));
    echo "Migration terminée — module OPK-FLAVOR prêt.\n";

} catch (Throwable $e) {
    $pdo->rollBack();
    echo "ERREUR : " . $e->getMessage() . "\n";
    exit(1);
}
