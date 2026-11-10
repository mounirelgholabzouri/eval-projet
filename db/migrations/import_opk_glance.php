<?php
/**
 * Migration : Module OPK-GLANCE — 20 questions contrôle continu
 * Basé sur Cours_M203_Glance_avant_TP02.pptx + TP-IDOCC-M203-02_Images-Glance.docx
 */

require_once __DIR__ . '/../../config/database.php';
$pdo = getDB();

$marker = __DIR__ . '/../.done_import_opk_glance';
if (file_exists($marker)) {
    echo "Déjà exécuté.\n";
    exit(0);
}

$pdo->beginTransaction();

try {
    // ── Module ──────────────────────────────────────────────────────────────
    $stmt = $pdo->prepare("SELECT id FROM modules WHERE nom = ?");
    $stmt->execute(['OPK-GLANCE']);
    $moduleId = $stmt->fetchColumn();

    if (!$moduleId) {
        $pdo->prepare("INSERT INTO modules (nom, description, actif, nb_questions_controle)
                        VALUES (?, ?, 1, 20)")
            ->execute([
                'OPK-GLANCE',
                'Gestion des images avec Glance — OpenStack M203 — Contrôle continu (20 questions)',
            ]);
        $moduleId = $pdo->lastInsertId();
        echo "Module OPK-GLANCE créé (id=$moduleId)\n";
    } else {
        echo "Module OPK-GLANCE existant (id=$moduleId)\n";
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
    // Format : [texte, type, points, [choix => is_correct, ...]]
    // is_correct : 1 = bonne réponse / 0 = mauvaise
    // Pour vrai_faux : choix 'Vrai' et 'Faux'

    $questions = [

        // ── QCM 1 ────────────────────────────────────────────────────────────
        [
            'texte'  => "Quel est le rôle principal du service Glance dans OpenStack ?",
            'type'   => 'qcm',
            'points' => 1.00,
            'choix'  => [
                "Gérer les instances de calcul et leur cycle de vie"                              => 0,
                "Cataloguer et fournir les images de disque utilisées par Nova"                   => 1,
                "Gérer les réseaux virtuels et les adresses IP"                                   => 0,
                "Authentifier les utilisateurs et les projets"                                    => 0,
            ],
        ],

        // ── QCM 2 ────────────────────────────────────────────────────────────
        [
            'texte'  => "Quel format de disque est utilisé par défaut dans les TPs M203 avec Glance ?",
            'type'   => 'qcm',
            'points' => 1.00,
            'choix'  => [
                "raw"   => 0,
                "iso"   => 0,
                "qcow2" => 1,
                "vmdk"  => 0,
            ],
        ],

        // ── QCM 3 ────────────────────────────────────────────────────────────
        [
            'texte'  => "Que signifie le format conteneur « bare » dans les propriétés d'une image Glance ?",
            'type'   => 'qcm',
            'points' => 1.00,
            'choix'  => [
                "L'image est compressée avec l'algorithme zlib"               => 0,
                "Il n'y a aucune enveloppe autour du disque"                  => 1,
                "L'image est chiffrée avec une clé symétrique"                => 0,
                "L'image est en lecture seule et ne peut pas être modifiée"   => 0,
            ],
        ],

        // ── QCM 4 ────────────────────────────────────────────────────────────
        [
            'texte'  => "Que représente la propriété virtual_size d'une image Glance ?",
            'type'   => 'qcm',
            'points' => 1.00,
            'choix'  => [
                "La taille du fichier image stocké sur le serveur"            => 0,
                "La taille du disque telle que vue par l'instance au démarrage" => 1,
                "La taille minimale du disque hôte pour héberger l'image"    => 0,
                "La quantité de RAM minimale pour démarrer l'image"          => 0,
            ],
        ],

        // ── QCM 5 ────────────────────────────────────────────────────────────
        [
            'texte'  => "Quel niveau de visibilité d'une image ne peut être défini que par un administrateur ?",
            'type'   => 'qcm',
            'points' => 1.00,
            'choix'  => [
                "private"   => 0,
                "shared"    => 0,
                "community" => 0,
                "public"    => 1,
            ],
        ],

        // ── QCM 6 ────────────────────────────────────────────────────────────
        [
            'texte'  => "Quel est le statut d'une image Glance créée mais dont les données n'ont pas encore été uploadées ?",
            'type'   => 'qcm',
            'points' => 1.00,
            'choix'  => [
                "active"   => 0,
                "pending"  => 0,
                "queued"   => 1,
                "inactive" => 0,
            ],
        ],

        // ── QCM 7 ────────────────────────────────────────────────────────────
        [
            'texte'  => "Quelle commande OpenStack CLI permet de télécharger une image Glance vers le poste local ?",
            'type'   => 'qcm',
            'points' => 1.00,
            'choix'  => [
                "openstack image create --file <fichier> <nom>"    => 0,
                "openstack image download --output <fichier> <nom>" => 0,
                "openstack image save --file <fichier> <nom>"       => 1,
                "openstack image export --file <fichier> <nom>"     => 0,
            ],
        ],

        // ── QCM 8 ────────────────────────────────────────────────────────────
        [
            'texte'  => "Lors du partage d'une image (visibilité shared), quelle est la séquence d'actions correcte ?",
            'type'   => 'qcm',
            'points' => 1.00,
            'choix'  => [
                "Le voisin accepte → le propriétaire ajoute ensuite le projet voisin"             => 0,
                "Le propriétaire ajoute le projet voisin → le voisin accepte le partage"          => 1,
                "L'admin approuve → le propriétaire partage → le voisin reçoit"                   => 0,
                "Le voisin envoie une demande → le propriétaire valide depuis Horizon"            => 0,
            ],
        ],

        // ── QCM 9 ────────────────────────────────────────────────────────────
        [
            'texte'  => "Quel code d'erreur HTTP reçoit-on quand un rôle member tente de rendre une image publique ?",
            'type'   => 'qcm',
            'points' => 1.00,
            'choix'  => [
                "401 Unauthorized" => 0,
                "403 Forbidden"    => 1,
                "404 Not Found"    => 0,
                "500 Server Error" => 0,
            ],
        ],

        // ── QCM 10 ───────────────────────────────────────────────────────────
        [
            'texte'  => "Que se passe-t-il si un propriétaire tente de supprimer une de ses propres images marquée comme protégée ?",
            'type'   => 'qcm',
            'points' => 1.00,
            'choix'  => [
                "La suppression réussit car il est propriétaire"                          => 0,
                "La suppression demande une confirmation administrateur"                  => 0,
                "La suppression échoue tant que la protection est active"                 => 1,
                "L'image est archivée pendant 7 jours avant suppression définitive"      => 0,
            ],
        ],

        // ── QCM 11 ───────────────────────────────────────────────────────────
        [
            'texte'  => "Quelle propriété d'une image Glance identifie le projet propriétaire ?",
            'type'   => 'qcm',
            'points' => 1.00,
            'choix'  => [
                "created_by" => 0,
                "project_id" => 0,
                "owner"      => 1,
                "user_id"    => 0,
            ],
        ],

        // ── QCM 12 ───────────────────────────────────────────────────────────
        [
            'texte'  => "Quelle commande OpenStack permet de modifier les métadonnées d'une image (min_disk, tags…) sans altérer son contenu binaire ?",
            'type'   => 'qcm',
            'points' => 1.00,
            'choix'  => [
                "openstack image update <nom>" => 0,
                "openstack image set <options> <nom>" => 1,
                "openstack image edit <nom>"   => 0,
                "openstack image patch <nom>"  => 0,
            ],
        ],

        // ── QCM 13 ───────────────────────────────────────────────────────────
        [
            'texte'  => "Si 23 projets utilisent la même image publique ubuntu-22.04, combien de copies physiques existent sur le serveur Glance ?",
            'type'   => 'qcm',
            'points' => 1.00,
            'choix'  => [
                "23 — une copie par projet"                        => 0,
                "2 — un maître et une réplique de sauvegarde"      => 0,
                "1 — une seule copie partagée"                     => 1,
                "Cela dépend de l'espace disque disponible"        => 0,
            ],
        ],

        // ── QCM 14 ───────────────────────────────────────────────────────────
        [
            'texte'  => "Quelle commande permet de lister uniquement les images publiques disponibles dans Glance ?",
            'type'   => 'qcm',
            'points' => 1.00,
            'choix'  => [
                "openstack image list --public"              => 1,
                "openstack image show --public"              => 0,
                "openstack image list --visibility=public"   => 0,
                "openstack image find --public"              => 0,
            ],
        ],

        // ── V/F 15 ───────────────────────────────────────────────────────────
        [
            'texte'  => "Une image Glance peut être modifiée (son contenu binaire) pendant qu'une instance l'utilise comme modèle.",
            'type'   => 'vrai_faux',
            'points' => 1.00,
            'choix'  => [
                "Vrai" => 0,
                "Faux" => 1,
            ],
        ],

        // ── V/F 16 ───────────────────────────────────────────────────────────
        [
            'texte'  => "Le format conteneur « bare » signifie qu'il n'y a aucune enveloppe autour du disque image.",
            'type'   => 'vrai_faux',
            'points' => 1.00,
            'choix'  => [
                "Vrai" => 1,
                "Faux" => 0,
            ],
        ],

        // ── V/F 17 ───────────────────────────────────────────────────────────
        [
            'texte'  => "Un utilisateur avec le rôle member peut supprimer n'importe quelle image publique du cloud OpenStack.",
            'type'   => 'vrai_faux',
            'points' => 1.00,
            'choix'  => [
                "Vrai" => 0,
                "Faux" => 1,
            ],
        ],

        // ── V/F 18 ───────────────────────────────────────────────────────────
        [
            'texte'  => "Avec le niveau de visibilité « community », l'image apparaît automatiquement dans la liste des images de tous les projets par défaut.",
            'type'   => 'vrai_faux',
            'points' => 1.00,
            'choix'  => [
                "Vrai" => 0,
                "Faux" => 1,
            ],
        ],

        // ── V/F 19 ───────────────────────────────────────────────────────────
        [
            'texte'  => "Au format qcow2, le fichier image peut occuper moins d'espace disque que la taille du disque virtuel qu'il représente.",
            'type'   => 'vrai_faux',
            'points' => 1.00,
            'choix'  => [
                "Vrai" => 1,
                "Faux" => 0,
            ],
        ],

        // ── V/F 20 ───────────────────────────────────────────────────────────
        [
            'texte'  => "Pour qu'une image partagée (shared) apparaisse dans la liste d'un projet voisin, ce projet doit d'abord accepter explicitement le partage.",
            'type'   => 'vrai_faux',
            'points' => 1.00,
            'choix'  => [
                "Vrai" => 1,
                "Faux" => 0,
            ],
        ],
    ];

    $insQ  = $pdo->prepare("INSERT INTO questions (module_id, partie_id, texte, type, points, ordre)
                             VALUES (?, ?, ?, ?, ?, ?)");
    $insC  = $pdo->prepare("INSERT INTO choix_reponses (question_id, texte, is_correct, ordre)
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
    echo "Migration terminée.\n";

} catch (Throwable $e) {
    $pdo->rollBack();
    echo "ERREUR : " . $e->getMessage() . "\n";
    exit(1);
}
