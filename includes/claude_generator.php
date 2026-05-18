<?php
/**
 * Génération de questions via IA (Anthropic, OpenAI, Google)
 * Utilise cURL natif PHP — aucune dépendance externe requise.
 */
require_once __DIR__ . '/ai_provider.php';

// ── Extraction de texte depuis un document uploadé ───────────

/**
 * Extrait le texte brut d'un fichier uploadé.
 * Retourne ['text' => string, 'is_pdf' => bool, 'pdf_base64' => string|null]
 */
function extractDocumentContent(string $filePath, string $mimeType, string $originalName = ''): array {
    $ext = strtolower(pathinfo($originalName ?: $filePath, PATHINFO_EXTENSION));

    // PDF → envoi natif à Claude (document block)
    if ($ext === 'pdf' || $mimeType === 'application/pdf') {
        return [
            'text'       => null,
            'is_pdf'     => true,
            'pdf_base64' => base64_encode(file_get_contents($filePath)),
        ];
    }

    // DOCX → extraction XML interne
    if ($ext === 'docx') {
        $text = extractDocxText($filePath);
        if (trim($text) === '') {
            throw new RuntimeException("Le fichier DOCX ne contient pas de texte extractible (document avec images uniquement ou format non standard). Exportez-le en .txt ou .pdf.");
        }
        return ['text' => $text, 'is_pdf' => false, 'pdf_base64' => null];
    }

    // TXT / MD / autres texte
    $text = file_get_contents($filePath);
    if ($text === false) throw new RuntimeException("Impossible de lire le fichier.");
    $text = trim(mb_substr($text, 0, 80000));
    if ($text === '') {
        throw new RuntimeException("Le fichier est vide.");
    }
    return ['text' => $text, 'is_pdf' => false, 'pdf_base64' => null];
}

/**
 * Extrait le texte d'un fichier DOCX (ZIP contenant word/document.xml)
 */
function extractDocxText(string $filePath): string {
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException("L'extension ZipArchive est requise pour lire les fichiers DOCX.");
    }
    $zip = new ZipArchive();
    if ($zip->open($filePath) !== true) {
        throw new RuntimeException("Impossible d'ouvrir le fichier DOCX.");
    }
    $xml = $zip->getFromName('word/document.xml');
    $zip->close();
    if ($xml === false) throw new RuntimeException("Structure DOCX invalide.");

    // Extraire le texte depuis les balises <w:t> (contenu textuel DOCX)
    // On insère des sauts de ligne sur </w:p> et </w:tr> avant l'extraction
    $xml = str_replace(['</w:p>', '</w:tr>'], ['</w:p>'."\n", '</w:tr>'."\n"], $xml);
    preg_match_all('/<w:t(?:\s[^>]*)?>([^<]*)<\/w:t>/s', $xml, $matches);
    if (!empty($matches[1])) {
        $text = implode(' ', array_filter(array_map('trim', $matches[1]), 'strlen'));
    } else {
        // Fallback : strip_tags sur le XML complet
        $text = strip_tags($xml);
    }
    $text = html_entity_decode($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
    $text = trim(preg_replace('/[ \t]{2,}/', ' ', preg_replace('/\n{3,}/', "\n\n", $text)));
    return mb_substr($text, 0, 80000);
}

// ── Appel API Claude ─────────────────────────────────────────

/**
 * Génère des questions via Claude à partir d'un contenu de cours.
 *
 * @param array  $docContent  Résultat de extractDocumentContent()
 * @param int    $moduleId    ID du module cible
 * @param int    $nbQuestions Nombre de questions à générer
 * @param array  $types       Types de questions demandés (qcm, vrai_faux, texte_libre)
 * @param string $niveau      Débutant / Confirmé / Mix
 * @param int    $noteMax     Notation sur 20 ou 40
 * @param string $apiKey      Clé API Anthropic
 * @return array              Questions générées (format prêt à insérer en BD)
 */
function genererQuestionsAvecClaude(
    array  $docContent,
    int    $nbQuestions,
    array  $types,
    string $niveau,
    int    $noteMax,
    string $apiKey,
    string $prompt = '',
    string $model = 'claude-sonnet-4-20250514',
    string $provider = 'anthropic'
): array {
    $typesStr = implode(', ', $types);

    // Calcul de la répartition des points pour atteindre noteMax
    $pointsParQuestion = round($noteMax / $nbQuestions, 2);

    $sourceInstruction = ($docContent['text'] !== null || $docContent['is_pdf'])
        ? "basées UNIQUEMENT sur le contenu fourni."
        : "basées sur le sujet/prompt fourni par le formateur.";

    $systemPrompt = <<<SYSTEM
Tu es un formateur expert en ingénierie pédagogique. Tu génères des questions d'évaluation à partir d'un contenu de cours.

RÈGLES ABSOLUES :
1. Génère exactement {$nbQuestions} questions {$sourceInstruction}
2. Types demandés : {$typesStr}.
3. Niveau : {$niveau}. Mix = 40% débutant, 40% intermédiaire, 20% avancé.
4. L'évaluation est notée sur {$noteMax} points. La somme des points de toutes les questions DOIT être exactement {$noteMax}.
5. Pour les QCM : exactement 4 choix (A, B, C, D), une seule bonne réponse.
6. Pour les Vrai/Faux : 2 choix ("Vrai" et "Faux"), exactement un correct.
7. Pour texte_libre : pas de choix, réponse attendue dans "corrige".
8. Réponds UNIQUEMENT avec un objet JSON valide, sans markdown, sans commentaires.

FORMAT JSON REQUIS :
{
  "questions": [
    {
      "texte": "Texte de la question",
      "type": "qcm|vrai_faux|texte_libre",
      "points": 2.0,
      "ordre": 1,
      "difficulte": "debutant|intermediaire|avance",
      "corrige": "Explication de la bonne réponse (pour le corrigé formateur)",
      "choix": [
        {"texte": "Texte du choix", "is_correct": true},
        {"texte": "Texte du choix", "is_correct": false}
      ]
    }
  ]
}
SYSTEM;

    // Construction du message utilisateur selon le type de document
    $messages = [];

    $promptExtra = $prompt !== '' ? "\nConsigne supplémentaire : {$prompt}" : '';

    if ($docContent['is_pdf']) {
        // Mode document PDF
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
                    'text' => "Génère {$nbQuestions} questions d'évaluation ({$typesStr}) sur ce document, notées sur {$noteMax} points au total.{$promptExtra}",
                ],
            ],
        ];
    } elseif ($docContent['text'] !== null) {
        // Mode document texte (DOCX / TXT)
        $contenu = trim($docContent['text']);
        if ($contenu === '') {
            throw new RuntimeException("Le contenu extrait du document est vide. Vérifiez votre fichier ou utilisez le champ Prompt pour décrire le sujet.");
        }
        $messages[] = [
            'role'    => 'user',
            'content' => "Voici le contenu du cours :\n\n---\n{$contenu}\n---\n\nGénère {$nbQuestions} questions d'évaluation ({$typesStr}) sur ce cours, notées sur {$noteMax} points au total.{$promptExtra}",
        ];
    } else {
        // Mode prompt seul (sans document)
        if ($prompt === '') throw new \RuntimeException("Un sujet ou un document est requis.");
        $messages[] = [
            'role'    => 'user',
            'content' => "Génère {$nbQuestions} questions d'évaluation ({$typesStr}) sur le sujet suivant, notées sur {$noteMax} points au total.\n\nSujet : {$prompt}",
        ];
    }

    // PDF natif uniquement supporté par Anthropic
    if ($docContent['is_pdf'] && $provider !== 'anthropic') {
        throw new RuntimeException("Les PDF natifs ne sont supportés qu'avec Anthropic. Exportez votre document en DOCX ou TXT pour utiliser OpenAI ou Google.");
    }

    // Appel API unifié
    // Ollama (CPU) est ~50× plus lent que les API cloud → on réduit max_tokens
    $maxOut = $provider === 'ollama' ? 2000 : 8192;
    $result = callAIUnified($provider, $apiKey, $model, $systemPrompt, $messages, $maxOut);

    if (!$result['success']) {
        throw new RuntimeException($result['error']);
    }

    // Extraire le JSON de la réponse (ignorer tout texte autour)
    $rawText = $result['text'];
    $json    = extractJsonFromText($rawText);

    $parsed = json_decode($json, true);
    if (!$parsed || empty($parsed['questions'])) {
        throw new RuntimeException("Le JSON généré est invalide : " . substr($rawText, 0, 300));
    }

    return $parsed['questions'];
}

/**
 * Extrait proprement un bloc JSON d'une réponse texte Claude
 */
function extractJsonFromText(string $text): string {
    // Chercher entre ```json ... ``` ou { ... }
    if (preg_match('/```json\s*([\s\S]+?)\s*```/i', $text, $m)) return trim($m[1]);
    if (preg_match('/```\s*([\s\S]+?)\s*```/i', $text, $m))      return trim($m[1]);
    // Trouver le premier { et le dernier }
    $start = strpos($text, '{');
    $end   = strrpos($text, '}');
    if ($start !== false && $end !== false && $end > $start) {
        return substr($text, $start, $end - $start + 1);
    }
    return $text;
}

// ── Insertion en base de données ─────────────────────────────

/**
 * Insère les questions générées dans la BD pour un module donné.
 * Remet l'ordre à la suite des questions existantes.
 */
function sauvegarderQuestionsGenerees(array $questions, int $moduleId): int {
    $pdo = getDB();

    // Garantir qu'une partie « Général » existe pour ce module (invariant métier)
    $stmt = $pdo->prepare("SELECT id FROM parties WHERE module_id = ? ORDER BY ordre ASC, id ASC LIMIT 1");
    $stmt->execute([$moduleId]);
    $partieId = (int)$stmt->fetchColumn();
    if ($partieId === 0) {
        $pdo->prepare("INSERT INTO parties (module_id, nom, ordre, actif) VALUES (?, 'Général', 1, 1)")
            ->execute([$moduleId]);
        $partieId = (int)$pdo->lastInsertId();
    }

    // Ordre de départ
    $stmt = $pdo->prepare("SELECT COALESCE(MAX(ordre), 0) FROM questions WHERE module_id = ?");
    $stmt->execute([$moduleId]);
    $ordre = (int)$stmt->fetchColumn();

    $inserted = 0;
    foreach ($questions as $q) {
        $ordre++;
        $type   = in_array($q['type'], ['qcm','vrai_faux','texte_libre','multiple']) ? $q['type'] : 'qcm';
        $points = max(0.5, (float)($q['points'] ?? 1));

        $stmt = $pdo->prepare(
            "INSERT INTO questions (module_id, partie_id, texte, type, points, ordre) VALUES (?,?,?,?,?,?)"
        );
        $stmt->execute([$moduleId, $partieId, trim($q['texte']), $type, $points, $ordre]);
        $questionId = (int)$pdo->lastInsertId();

        // Choix de réponses
        if (!empty($q['choix']) && $type !== 'texte_libre') {
            foreach ($q['choix'] as $i => $c) {
                $pdo->prepare(
                    "INSERT INTO choix_reponses (question_id, texte, is_correct, ordre) VALUES (?,?,?,?)"
                )->execute([$questionId, trim($c['texte']), $c['is_correct'] ? 1 : 0, $i + 1]);
            }
        }
        $inserted++;
    }
    return $inserted;
}
