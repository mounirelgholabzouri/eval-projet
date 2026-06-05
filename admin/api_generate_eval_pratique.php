<?php
/**
 * AJAX — Génère un sujet d'évaluation pratique + structure grille via IA.
 * POST params : prompt_sujet, module_code, module_intitule, filiere,
 *               etablissement, annee, duree, note_max, nb_parties,
 *               questions_par_partie, document (file, optional)
 * Response JSON : { success, eval_id, titre, sujet_html, parties[] }
 */
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/claude_generator.php';
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Méthode invalide.']);
    exit;
}

$pdo = getDB();

$provider  = getAIProvider();
$apiKey    = getAPIKeyForProvider($provider);
$model     = getAIModel();

if (!$apiKey) {
    echo json_encode(['success' => false, 'error' => 'Clé API IA non configurée. Allez dans Paramètres IA.']);
    exit;
}

$prompt          = trim($_POST['prompt_sujet'] ?? '');
$moduleCode      = trim($_POST['module_code'] ?? '');
$moduleIntitule  = trim($_POST['module_intitule'] ?? '');
$filiere         = trim($_POST['filiere'] ?? '');
$etablissement   = trim($_POST['etablissement'] ?? '');
$annee           = trim($_POST['annee'] ?? getAnneeFormation());
$duree           = trim($_POST['duree'] ?? '2h');
$noteMax         = in_array((int)($_POST['note_max'] ?? 20), [20, 40]) ? (int)$_POST['note_max'] : 20;
$nbParties       = max(2, min(6, (int)($_POST['nb_parties'] ?? 4)));
$qParPartie      = max(1, min(4, (int)($_POST['questions_par_partie'] ?? 2)));

$hasFile   = !empty($_FILES['document']['name']) && $_FILES['document']['error'] === UPLOAD_ERR_OK;
$hasPrompt = $prompt !== '';

if (!$hasFile && !$hasPrompt) {
    echo json_encode(['success' => false, 'error' => 'Veuillez saisir un sujet ou uploader un document.']);
    exit;
}

// ── Extraction document si fourni ───────────────────────────────
$docContent = ['text' => null, 'is_pdf' => false, 'pdf_base64' => null];
if ($hasFile) {
    $allowed = ['pdf', 'docx', 'txt', 'md'];
    $ext     = strtolower(pathinfo($_FILES['document']['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed)) {
        echo json_encode(['success' => false, 'error' => 'Format non supporté. Utilisez PDF, DOCX, TXT ou MD.']);
        exit;
    }
    if ($_FILES['document']['size'] > 20 * 1024 * 1024) {
        echo json_encode(['success' => false, 'error' => 'Fichier trop volumineux (max 20 Mo).']);
        exit;
    }
    try {
        $docContent = extractDocumentContent(
            $_FILES['document']['tmp_name'],
            $_FILES['document']['type'],
            $_FILES['document']['name']
        );
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

// ── Calcul points par partie / question ─────────────────────────
$ptsTotalParties = $noteMax; // toutes les parties = note max
$ptsParPartie    = round($noteMax / $nbParties * 2) / 2;           // multiple de 0.5
$ptsParQuestion  = round($ptsParPartie / $qParPartie * 2) / 2;    // multiple de 0.5

// ── Système prompt IA ────────────────────────────────────────────
$sourceInstruction = ($docContent['text'] !== null || $docContent['is_pdf'])
    ? "en te basant UNIQUEMENT sur le contenu du document fourni"
    : "en te basant sur le sujet/prompt du formateur";

$systemPrompt = <<<SYSTEM
Tu es un formateur expert en ingénierie pédagogique OFPPT. Tu génères des sujets d'évaluation pratique structurés.

RÈGLES ABSOLUES :
1. Génère exactement {$nbParties} parties {$sourceInstruction}.
2. Chaque partie contient exactement {$qParPartie} question(s)/tâche(s) pratique(s).
3. Chaque partie vaut {$ptsParPartie} points. Chaque question vaut {$ptsParQuestion} points.
4. La somme totale doit être exactement {$noteMax} points.
5. Les tâches sont pratiques (manipulations, réalisations, configurations, calculs…) — pas de QCM.
6. Chaque question a 2 à 4 critères d'évaluation (ce que le correcteur vérifie).
7. Réponds UNIQUEMENT avec un objet JSON valide, sans markdown, sans commentaires.

FORMAT JSON REQUIS (respecte EXACTEMENT cette structure) :
{
  "titre": "Contrôle de Note Pratique N°1",
  "consignes": "Texte des consignes générales (durée, matériel, règles...)",
  "parties": [
    {
      "numero": 1,
      "titre": "Titre descriptif de la partie 1",
      "contexte": "Mise en situation / contexte de la partie (2-3 phrases)",
      "points": {$ptsParPartie},
      "questions": [
        {
          "numero": 1,
          "texte": "Énoncé complet et précis de la tâche pratique",
          "points": {$ptsParQuestion},
          "criteres": [
            "Critère d'évaluation 1",
            "Critère d'évaluation 2"
          ]
        }
      ]
    }
  ]
}
SYSTEM;

// ── Construction messages ────────────────────────────────────────
$messages = [];
$infoModule = '';
if ($moduleCode || $moduleIntitule || $filiere) {
    $infoModule = "\nModule : " . ($moduleCode ? $moduleCode . ' — ' : '') . $moduleIntitule;
    if ($filiere) $infoModule .= " | Filière : $filiere";
}
$promptExtra = $prompt !== '' ? "\nSujet / consignes du formateur : $prompt" : '';

if ($docContent['is_pdf']) {
    $messages[] = [
        'role'    => 'user',
        'content' => [
            [
                'type'   => 'document',
                'source' => [
                    'type'       => 'base64',
                    'media_type' => 'application/pdf',
                    'data'       => $docContent['pdf_base64'],
                ],
            ],
            [
                'type' => 'text',
                'text' => "Génère un sujet d'évaluation pratique en {$nbParties} parties ({$qParPartie} question(s)/partie) sur {$noteMax} points à partir de ce document.{$infoModule}{$promptExtra}",
            ],
        ],
    ];
} elseif ($docContent['text'] !== null) {
    $contenu = trim($docContent['text']);
    $messages[] = [
        'role'    => 'user',
        'content' => "Voici le contenu du cours :\n\n---\n{$contenu}\n---\n\nGénère un sujet d'évaluation pratique en {$nbParties} parties ({$qParPartie} question(s)/partie) sur {$noteMax} points.{$infoModule}{$promptExtra}",
    ];
} else {
    $messages[] = [
        'role'    => 'user',
        'content' => "Génère un sujet d'évaluation pratique en {$nbParties} parties ({$qParPartie} question(s)/partie) sur {$noteMax} points.{$infoModule}{$promptExtra}",
    ];
}

if ($docContent['is_pdf'] && $provider !== 'anthropic') {
    echo json_encode(['success' => false, 'error' => 'Les PDF natifs nécessitent Anthropic. Convertissez en TXT/DOCX pour OpenAI/Google.']);
    exit;
}

// ── Appel IA ─────────────────────────────────────────────────────
try {
    $result = callAIUnified($provider, $apiKey, $model, $systemPrompt, $messages, 8192);
    if (!$result['success']) {
        echo json_encode(['success' => false, 'error' => $result['error']]);
        exit;
    }

    $rawText = $result['text'];
    $json    = extractJsonFromText($rawText);
    if (!$json) {
        echo json_encode(['success' => false, 'error' => 'Réponse IA non valide. Réessayez.', 'raw' => mb_substr($rawText, 0, 500)]);
        exit;
    }

    $data    = json_decode($json, true);
    $parties = $data['parties'] ?? [];

    if (empty($parties)) {
        echo json_encode(['success' => false, 'error' => 'Structure IA invalide : aucune partie trouvée.']);
        exit;
    }

    // ── Sauvegarde en base ────────────────────────────────────────
    $titre = sanitize($data['titre'] ?? 'Contrôle Pratique N°1');

    $stmt = $pdo->prepare("
        INSERT INTO eval_pratique
            (titre, module_code, module_intitule, filiere, etablissement, annee, duree, note_max, prompt_sujet, sujet_texte, structure_json)
        VALUES (?,?,?,?,?,?,?,?,?,?,?)
    ");
    $sujettexte = buildSujetTexte($data, $moduleCode, $moduleIntitule, $filiere, $duree, $noteMax);
    $stmt->execute([
        $titre,
        sanitize($moduleCode),
        sanitize($moduleIntitule),
        sanitize($filiere),
        sanitize($etablissement),
        sanitize($annee),
        sanitize($duree),
        $noteMax,
        sanitize($prompt),
        $sujettexte,
        $json,
    ]);
    $evalId = (int)$pdo->lastInsertId();

    // ── Insérer parties et questions ──────────────────────────────
    foreach ($parties as $p) {
        $stmtP = $pdo->prepare("
            INSERT INTO eval_pratique_parties (eval_id, numero, titre, points, ordre)
            VALUES (?,?,?,?,?)
        ");
        $stmtP->execute([
            $evalId,
            (int)($p['numero'] ?? 0),
            sanitize($p['titre'] ?? ''),
            (float)($p['points'] ?? $ptsParPartie),
            (int)($p['numero'] ?? 0),
        ]);
        $partieId = (int)$pdo->lastInsertId();

        foreach ($p['questions'] ?? [] as $q) {
            $criteresJson = json_encode($q['criteres'] ?? [], JSON_UNESCAPED_UNICODE);
            $stmtQ = $pdo->prepare("
                INSERT INTO eval_pratique_questions (partie_id, eval_id, numero, texte, points, criteres, ordre)
                VALUES (?,?,?,?,?,?,?)
            ");
            $stmtQ->execute([
                $partieId,
                $evalId,
                (int)($q['numero'] ?? 0),
                sanitize($q['texte'] ?? ''),
                (float)($q['points'] ?? $ptsParQuestion),
                $criteresJson,
                (int)($q['numero'] ?? 0),
            ]);
        }
    }

    echo json_encode([
        'success'     => true,
        'eval_id'     => $evalId,
        'titre'       => $titre,
        'consignes'   => $data['consignes'] ?? '',
        'parties'     => $parties,
        'note_max'    => $noteMax,
        'sujet_texte' => $sujettexte,
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

// ── Helper : construit le texte HTML du sujet ────────────────────
function buildSujetTexte(array $data, string $code, string $intitule, string $filiere, string $duree, int $noteMax): string {
    $lines = [];
    $lines[] = '<h2 class="sujet-titre">' . htmlspecialchars($data['titre'] ?? 'Contrôle Pratique N°1', ENT_QUOTES, 'UTF-8') . '</h2>';
    if ($code || $intitule) {
        $lines[] = '<p class="sujet-module">' . htmlspecialchars(($code ? $code . ' — ' : '') . $intitule, ENT_QUOTES, 'UTF-8') . '</p>';
    }
    $lines[] = '<p class="sujet-meta">Durée : <strong>' . htmlspecialchars($duree, ENT_QUOTES, 'UTF-8') . '</strong> &nbsp;|&nbsp; Note sur : <strong>' . $noteMax . ' pts</strong></p>';

    if (!empty($data['consignes'])) {
        $lines[] = '<div class="sujet-consignes"><strong>Consignes :</strong> ' . nl2br(htmlspecialchars($data['consignes'], ENT_QUOTES, 'UTF-8')) . '</div>';
    }

    foreach ($data['parties'] ?? [] as $p) {
        $lines[] = '<div class="sujet-partie">';
        $lines[] = '<h3>Partie ' . (int)$p['numero'] . ' — ' . htmlspecialchars($p['titre'] ?? '', ENT_QUOTES, 'UTF-8') . ' <span class="pts">(' . (float)$p['points'] . ' pts)</span></h3>';
        if (!empty($p['contexte'])) {
            $lines[] = '<p class="contexte">' . nl2br(htmlspecialchars($p['contexte'], ENT_QUOTES, 'UTF-8')) . '</p>';
        }
        foreach ($p['questions'] ?? [] as $q) {
            $lines[] = '<div class="sujet-question">';
            $lines[] = '<p><strong>Q' . (int)$q['numero'] . '.</strong> (' . (float)$q['points'] . ' pts) ' . nl2br(htmlspecialchars($q['texte'] ?? '', ENT_QUOTES, 'UTF-8')) . '</p>';
            $lines[] = '</div>';
        }
        $lines[] = '</div>';
    }

    return implode("\n", $lines);
}
