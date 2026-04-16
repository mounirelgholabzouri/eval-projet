<?php
/**
 * Génération de questions via l'API Claude (Anthropic)
 * Utilise cURL natif PHP — aucune dépendance externe requise.
 */

// ── Extraction de texte depuis un document uploadé ───────────

/**
 * Extrait le texte brut d'un fichier uploadé.
 * Retourne ['text' => string, 'is_pdf' => bool, 'pdf_base64' => string|null]
 */
function extractDocumentContent(string $filePath, string $mimeType): array {
    $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

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
        return ['text' => extractDocxText($filePath), 'is_pdf' => false, 'pdf_base64' => null];
    }

    // TXT / MD / autres texte
    $text = file_get_contents($filePath);
    if ($text === false) throw new RuntimeException("Impossible de lire le fichier.");
    return ['text' => mb_substr($text, 0, 80000), 'is_pdf' => false, 'pdf_base64' => null];
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

    // Supprimer les balises XML et décoder les entités
    $text = strip_tags(str_replace(['</w:p>', '</w:tr>'], "\n", $xml));
    $text = html_entity_decode($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
    return mb_substr(preg_replace('/\s{3,}/', "\n\n", $text), 0, 80000);
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
    string $prompt = ''
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
        $messages[] = [
            'role'    => 'user',
            'content' => "Voici le contenu du cours :\n\n---\n{$docContent['text']}\n---\n\nGénère {$nbQuestions} questions d'évaluation ({$typesStr}) sur ce cours, notées sur {$noteMax} points au total.{$promptExtra}",
        ];
    } else {
        // Mode prompt seul (sans document)
        if ($prompt === '') throw new \RuntimeException("Un sujet ou un document est requis.");
        $messages[] = [
            'role'    => 'user',
            'content' => "Génère {$nbQuestions} questions d'évaluation ({$typesStr}) sur le sujet suivant, notées sur {$noteMax} points au total.\n\nSujet : {$prompt}",
        ];
    }

    // Appel API
    $payload = json_encode([
        'model'      => 'claude-opus-4-6',
        'max_tokens' => 8192,
        'system'     => $systemPrompt,
        'messages'   => $messages,
    ]);

    $headers = [
        'Content-Type: application/json',
        'x-api-key: ' . $apiKey,
        'anthropic-version: 2023-06-01',
        'anthropic-beta: pdfs-2024-09-25',  // support PDF natif
    ];

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 120,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr) throw new RuntimeException("Erreur réseau : $curlErr");
    if ($httpCode !== 200) {
        $errData = json_decode($response, true);
        $errMsg  = $errData['error']['message'] ?? "HTTP $httpCode";
        throw new RuntimeException("Erreur API Claude : $errMsg");
    }

    $data = json_decode($response, true);
    if (!$data || empty($data['content'][0]['text'])) {
        throw new RuntimeException("Réponse Claude invalide ou vide.");
    }

    // Extraire le JSON de la réponse (ignorer tout texte autour)
    $rawText = $data['content'][0]['text'];
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
            "INSERT INTO questions (module_id, texte, type, points, ordre) VALUES (?,?,?,?,?)"
        );
        $stmt->execute([$moduleId, trim($q['texte']), $type, $points, $ordre]);
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
