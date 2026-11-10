<?php
/**
 * Publie un test JSON dans l'application : module (par code) + évaluation CC/EFM + questions.
 * Clés optionnelles : code_module, filiere, type_evaluation (CC|EFM), numero_cc.
 *
 * CLI : php db/publier_test.php fichier.json [--dry-run]
 *  --dry-run  valide le JSON sans rien écrire
 */

require_once __DIR__ . '/../includes/functions.php';

function _pt_normType(string $raw): string {
    $t = strtolower($raw);
    return match (true) {
        str_contains($t, 'glisser') || str_contains($t, 'drag') => 'glisser_deposer',
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
    $code   = strtoupper(trim($data['code_module'] ?? ''));
    if ($code === '' && preg_match('/^([A-Za-z]{1,8}\d{2,4})\b/u', $module, $m)) $code = strtoupper($m[1]);
    $intitule = trim(preg_replace('/^' . preg_quote($code, '/') . '\s*[-–—:]?\s*/iu', '', $module)) ?: $module;
    $typeEval = strtolower(trim($data['type_evaluation'] ?? 'cc')) === 'efm' ? 'efm' : 'cc';
    $erreurs = [];
    if ($module === '') $erreurs[] = "clé 'module' manquante";
    if (!preg_match('/^[A-Z0-9]{2,20}$/', $code)) $erreurs[] = "code module introuvable (clé 'code_module' ou 'module' commençant par ex. « M203 »)";
    if ($sujet === '')  $erreurs[] = "clé 'sujet' (ou 'titre') manquante";
    if (empty($data['questions']) || !is_array($data['questions'])) $erreurs[] = "aucune question";

    $questions = [];
    foreach ($data['questions'] ?? [] as $i => $q) {
        $n     = $i + 1;
        $texte = trim($q['enonce'] ?? $q['question'] ?? '');
        $type  = _pt_normType($q['type'] ?? '');
        $choix = [];
        if ($texte === '') { $erreurs[] = "Q$n : énoncé vide"; continue; }

        if ($type === 'glisser_deposer') {
            // "gabarit" à trous {{1}}..{{n}} ; "bonnes" dans l'ordre des trous (ordre = n° du trou) ; "leurres" facultatifs
            if (!empty($q['gabarit'])) $texte .= "\n---\n" . trim($q['gabarit']);
            [, $gabarit] = splitGlisserDeposer($texte);
            preg_match_all('/\{\{(\d+)\}\}/', $gabarit, $m);
            $trous  = array_map('intval', $m[1]);
            $bonnes = array_values((array)($q['bonnes'] ?? []));
            if ($gabarit === '') $erreurs[] = "Q$n : glisser-déposer sans 'gabarit' (ou séparateur ---)";
            if ($trous !== range(1, max(1, count($trous))) || count($trous) !== count($bonnes)) {
                $erreurs[] = "Q$n : les trous doivent être {{1}}..{{n}} une fois chacun, avec autant de 'bonnes' (" . count($trous) . " trous, " . count($bonnes) . " bonnes)";
            }
            foreach ($bonnes as $b)                     $choix[] = [(string)$b, 1];
            foreach ((array)($q['leurres'] ?? []) as $l) $choix[] = [(string)$l, 0];
            $txts = array_column($choix, 0);
            if (count($txts) !== count(array_unique($txts))) $erreurs[] = "Q$n : étiquette en double (bonne ou leurre)";
        } elseif ($type === 'qcm' || $type === 'multiple') {
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
        'code'        => $code,
        'filiere'     => strtoupper(trim($data['filiere'] ?? '')) ?: null,
        'intitule'    => mb_substr($intitule, 0, 150),
        'type'        => $typeEval,
        'numero'      => isset($data['numero_cc']) ? (int)$data['numero_cc'] : null,
        'sujet'       => mb_substr($sujet, 0, 255),
        'duree'       => (int)($data['duree_minutes'] ?? ($typeEval === 'efm' ? 120 : 30)),
        'note_max'    => (int)($data['note_max'] ?? ($typeEval === 'efm' ? 40 : 20)),
        'quota'       => isset($data['nb_questions']) ? (int)$data['nb_questions'] : null,
        'questions'   => $questions,
    ];
}

/**
 * Publie le test : module (retrouvé par son code) + évaluation CC/EFM (contenu = sujet) + questions.
 * Ré-exécutable sans doublon. Retourne module_id, evaluation_id, nom, importees, ignorees, total.
 */
function publierTest(PDO $pdo, string $jsonPath): array {
    $t = chargerTest($jsonPath);

    $pdo->beginTransaction();
    try {
        $moduleId = trouverOuCreerModule($t['code'], $t['filiere'], $t['intitule']);

        $s = $pdo->prepare("SELECT id FROM evaluations WHERE module_id = ? AND type = ? AND contenu = ? LIMIT 1");
        $s->execute([$moduleId, $t['type'], $t['sujet']]);
        $evalId = (int)$s->fetchColumn();
        if (!$evalId) {
            $numero = $t['numero'];
            if ($t['type'] === 'cc' && !$numero) {
                $numero = 1;
                foreach (getEvaluationsModule($moduleId) as $e) {
                    if ($e['type'] === 'cc' && $e['mode'] === 'theorique') $numero = max($numero, (int)$e['numero'] + 1);
                }
            }
            $evalId = creerEvaluation([
                'module_id' => $moduleId, 'type' => $t['type'], 'mode' => 'theorique', 'numero' => $numero,
                'contenu' => $t['sujet'], 'duree_minutes' => $t['duree'], 'note_max' => $t['note_max'],
                'nb_questions' => $t['quota'], 'actif' => true,
            ]);
        }

        $chk = $pdo->prepare("SELECT id FROM questions WHERE module_id = ? AND texte = ?");
        $importees = $ignorees = 0;
        $ids = [];
        foreach ($t['questions'] as $q) {
            $chk->execute([$moduleId, $q['texte']]);
            if ($qid = (int)$chk->fetchColumn()) {
                $ignorees++;
            } else {
                $choix = array_map(fn($c) => ['texte' => $c[0], 'is_correct' => $c[1]], $q['choix']);
                $qid = creerQuestion($moduleId, $q['type'], $q['texte'], $q['points'], $choix);
                $importees++;
            }
            $ids[] = $qid;
        }
        ajouterQuestionsEvaluation($evalId, $ids);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    return ['nom' => getEvaluation($evalId)['nom'], 'module_id' => $moduleId, 'evaluation_id' => $evalId,
            'importees' => $importees, 'ignorees' => $ignorees, 'total' => count($t['questions'])];
}

if (PHP_SAPI === 'cli' && realpath($argv[0] ?? '') === __FILE__) {
    $args = array_slice($argv, 1);
    $path = current(array_filter($args, fn($a) => !str_starts_with($a, '--')));
    if (!$path) { fwrite(STDERR, "Usage : php db/publier_test.php fichier.json [--dry-run]\n"); exit(1); }
    try {
        if (in_array('--dry-run', $args, true)) {
            $t = chargerTest($path);
            echo "OK : " . strtoupper($t['type']) . " « {$t['code']} — {$t['sujet']} » — " . count($t['questions']) . " question(s) valides\n";
            exit(0);
        }
        $r = publierTest(getDB(), $path);
        echo "Publié : « {$r['nom']} » (module #{$r['module_id']}, évaluation #{$r['evaluation_id']})\n";
        echo "Questions : {$r['importees']} importée(s), {$r['ignorees']} déjà présente(s), {$r['total']} au total\n";
        echo "Admin : http://eval-projet.test/admin/evaluations.php?action=edit&id={$r['evaluation_id']}\n";
    } catch (Throwable $e) {
        fwrite(STDERR, $e->getMessage() . "\n");
        exit(1);
    }
}
