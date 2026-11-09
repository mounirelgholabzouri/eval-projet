<?php
/**
 * Publie un test JSON dans l'application : module + évaluation + partie + questions.
 *
 * CLI : php db/publier_test.php fichier.json [--dry-run]
 *  --dry-run  valide le JSON sans rien écrire
 */

require_once __DIR__ . '/../includes/functions.php';

function _pt_normType(string $raw): string {
    $t = strtolower($raw);
    return match (true) {
        str_contains($t, 'multiple')                         => 'multiple',
        str_contains($t, 'vrai') || str_contains($t, 'faux') => 'vrai_faux',
        str_contains($t, 'qcm') || str_contains($t, 'unique') || $t === '' => 'qcm',
        default                                              => 'texte_libre',
    };
}

function _pt_parseOption(string $opt): array {
    if (preg_match('/^([A-Ha-h])\s*[.)]\s*(.+)$/su', trim($opt), $m)) {
        return [strtoupper($m[1]), trim($m[2])];
    }
    return ['', trim($opt)];
}

function _pt_lettresCorrectes(array $q): array {
    $raw = $q['reponses_correctes'] ?? $q['reponse_correcte'] ?? [];
    if (is_string($raw)) $raw = preg_split('/[\s,;]+/', $raw, -1, PREG_SPLIT_NO_EMPTY);
    return array_map(fn($l) => strtoupper(trim((string)$l)), (array)$raw);
}

/** Lit et valide le JSON ; lève une exception listant toutes les erreurs. */
function chargerTest(string $jsonPath): array {
    if (!is_file($jsonPath)) throw new RuntimeException("Fichier introuvable : $jsonPath");
    $data = json_decode(ltrim(file_get_contents($jsonPath), "\xEF\xBB\xBF"), true);
    if (json_last_error() !== JSON_ERROR_NONE) throw new RuntimeException('JSON invalide : ' . json_last_error_msg());
    $data = $data['evaluation'] ?? $data;

    $module = trim($data['module'] ?? '');
    $sujet  = trim($data['sujet'] ?? $data['titre'] ?? '');
    $erreurs = [];
    if ($module === '') $erreurs[] = "clé 'module' manquante";
    if ($sujet === '')  $erreurs[] = "clé 'sujet' (ou 'titre') manquante";
    if (empty($data['questions']) || !is_array($data['questions'])) $erreurs[] = "aucune question";

    $questions = [];
    foreach ($data['questions'] ?? [] as $i => $q) {
        $n     = $i + 1;
        $texte = trim($q['enonce'] ?? $q['question'] ?? '');
        $type  = _pt_normType($q['type'] ?? '');
        $choix = [];
        if ($texte === '') { $erreurs[] = "Q$n : énoncé vide"; continue; }

        if ($type === 'qcm' || $type === 'multiple') {
            $bonnes = _pt_lettresCorrectes($q);
            foreach ($q['options'] ?? [] as $opt) {
                [$lettre, $txt] = _pt_parseOption($opt);
                $choix[] = [$txt, in_array($lettre, $bonnes, true) ? 1 : 0];
            }
            $nbBonnes = array_sum(array_column($choix, 1));
            if (count($choix) < 2)                    $erreurs[] = "Q$n : moins de 2 options";
            if ($nbBonnes === 0)                      $erreurs[] = "Q$n : aucune bonne réponse ne correspond aux options";
            if ($type === 'qcm' && $nbBonnes > 1)     $erreurs[] = "Q$n : QCM choix unique avec plusieurs bonnes réponses";
        } elseif ($type === 'vrai_faux') {
            $rep  = strtolower(trim((string)($q['reponse_correcte'] ?? '')));
            $vrai = in_array($rep, ['vrai', 'true', 'v', '1'], true);
            if (!$vrai && !in_array($rep, ['faux', 'false', 'f', '0'], true)) $erreurs[] = "Q$n : vrai/faux sans réponse 'Vrai' ou 'Faux'";
            $choix = [['Vrai', $vrai ? 1 : 0], ['Faux', $vrai ? 0 : 1]];
        } else {
            $cles = $q['reponse_attendue_cles'] ?? $q['reponses_correctes'] ?? (isset($q['reponse_correcte']) ? [$q['reponse_correcte']] : []);
            foreach ((array)$cles as $c) $choix[] = [(string)$c, 1];
            if (!$choix) $erreurs[] = "Q$n : texte libre sans 'reponse_attendue_cles'";
        }
        $questions[] = ['texte' => $texte, 'type' => $type, 'points' => (float)($q['points'] ?? 1), 'choix' => $choix];
    }

    if ($erreurs) throw new RuntimeException("Test refusé :\n  - " . implode("\n  - ", $erreurs));

    return [
        'module_nom'  => mb_substr("$module — $sujet", 0, 150),
        'sujet'       => mb_substr($sujet, 0, 200),
        'description' => $data['description'] ?? null,
        'duree'       => (int)($data['duree_minutes'] ?? 30),
        'note_max'    => (int)($data['note_max'] ?? 20),
        'quota'       => isset($data['nb_questions']) ? (int)$data['nb_questions'] : null,
        'questions'   => $questions,
    ];
}

/** Publie le test ; ré-exécutable sans doublon. Retourne ['module_id', 'evaluation_id', 'importees', 'ignorees']. */
function publierTest(PDO $pdo, string $jsonPath): array {
    $t = chargerTest($jsonPath);
    $typeEval = in_array('texte_libre', array_column($t['questions'], 'type'), true) ? 'mixte' : 'qcm';

    $pdo->beginTransaction();
    try {
        $s = $pdo->prepare("SELECT id FROM modules WHERE nom = ?");
        $s->execute([$t['module_nom']]);
        $moduleId = (int)$s->fetchColumn();
        if (!$moduleId) {
            $pdo->prepare("INSERT INTO modules (nom, description, actif, nb_questions_controle) VALUES (?, ?, 1, ?)")
                ->execute([$t['module_nom'], $t['description'], $t['quota']]);
            $moduleId = (int)$pdo->lastInsertId();
        }

        $s = $pdo->prepare("SELECT id FROM evaluations WHERE module_id = ? ORDER BY id LIMIT 1");
        $s->execute([$moduleId]);
        $evalId = (int)$s->fetchColumn();
        if (!$evalId) {
            $pdo->prepare("INSERT INTO evaluations (module_id, nom, type, duree_minutes, note_max, nb_questions, actif) VALUES (?, ?, ?, ?, ?, ?, 1)")
                ->execute([$moduleId, $t['module_nom'], $typeEval, $t['duree'], $t['note_max'], $t['quota']]);
            $evalId = (int)$pdo->lastInsertId();
        }

        $s = $pdo->prepare("SELECT id FROM parties WHERE module_id = ? AND nom = ?");
        $s->execute([$moduleId, $t['sujet']]);
        $partieId = (int)$s->fetchColumn();
        if (!$partieId) {
            $s = $pdo->prepare("SELECT COALESCE(MAX(ordre),0)+1 FROM parties WHERE module_id = ?");
            $s->execute([$moduleId]);
            $pdo->prepare("INSERT INTO parties (module_id, nom, ordre, actif) VALUES (?, ?, ?, 1)")
                ->execute([$moduleId, $t['sujet'], (int)$s->fetchColumn()]);
            $partieId = (int)$pdo->lastInsertId();
        }

        $chk  = $pdo->prepare("SELECT id FROM questions WHERE partie_id = ? AND texte = ?");
        $insQ = $pdo->prepare("INSERT INTO questions (module_id, partie_id, texte, type, points, ordre) VALUES (?, ?, ?, ?, ?, ?)");
        $insC = $pdo->prepare("INSERT INTO choix_reponses (question_id, texte, is_correct, ordre) VALUES (?, ?, ?, ?)");
        $importees = $ignorees = 0;
        foreach ($t['questions'] as $i => $q) {
            $chk->execute([$partieId, $q['texte']]);
            if ($chk->fetchColumn()) { $ignorees++; continue; }
            $insQ->execute([$moduleId, $partieId, $q['texte'], $q['type'], $q['points'], $i + 1]);
            $qid = (int)$pdo->lastInsertId();
            foreach ($q['choix'] as $o => [$txt, $ok]) $insC->execute([$qid, mb_substr($txt, 0, 500), $ok, $o + 1]);
            $importees++;
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    return ['module_nom' => $t['module_nom'], 'module_id' => $moduleId, 'evaluation_id' => $evalId,
            'importees' => $importees, 'ignorees' => $ignorees, 'total' => count($t['questions'])];
}

if (PHP_SAPI === 'cli' && realpath($argv[0] ?? '') === __FILE__) {
    $args = array_slice($argv, 1);
    $path = current(array_filter($args, fn($a) => !str_starts_with($a, '--')));
    if (!$path) { fwrite(STDERR, "Usage : php db/publier_test.php fichier.json [--dry-run]\n"); exit(1); }
    try {
        if (in_array('--dry-run', $args, true)) {
            $t = chargerTest($path);
            echo "OK : « {$t['module_nom']} » — " . count($t['questions']) . " question(s) valides\n";
            exit(0);
        }
        $r = publierTest(getDB(), $path);
        echo "Publié : « {$r['module_nom']} » (module #{$r['module_id']}, évaluation #{$r['evaluation_id']})\n";
        echo "Questions : {$r['importees']} importée(s), {$r['ignorees']} déjà présente(s), {$r['total']} au total\n";
        echo "Admin : http://eval-projet.test/admin/gestion_questions.php?module_id={$r['module_id']}\n";
    } catch (Throwable $e) {
        fwrite(STDERR, $e->getMessage() . "\n");
        exit(1);
    }
}
