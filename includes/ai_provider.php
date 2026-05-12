<?php
/**
 * Wrapper unifié multi-fournisseurs IA
 * Supporte : Anthropic (Claude), OpenAI (GPT), Google (Gemini), Ollama (local)
 */

function getAIProvider(): string {
    $pdo = getDB();
    try {
        $stmt = $pdo->prepare("SELECT valeur FROM config WHERE cle = 'ai_provider' LIMIT 1");
        $stmt->execute();
        return trim($stmt->fetchColumn() ?: 'anthropic');
    } catch (Exception $e) {
        return 'anthropic';
    }
}

function getAPIKeyForProvider(string $provider): string {
    $pdo = getDB();
    // Ollama n'a pas de clé API — on retourne une chaîne vide (autorisée)
    if ($provider === 'ollama') return '';
    $cle = match($provider) {
        'openai' => 'openai_api_key',
        'google' => 'google_api_key',
        default  => 'anthropic_api_key',
    };
    try {
        $stmt = $pdo->prepare("SELECT valeur FROM config WHERE cle = ? LIMIT 1");
        $stmt->execute([$cle]);
        return trim($stmt->fetchColumn() ?: '');
    } catch (Exception $e) {
        return '';
    }
}

function getOllamaBaseUrl(): string {
    $pdo = getDB();
    try {
        $stmt = $pdo->prepare("SELECT valeur FROM config WHERE cle = 'ollama_base_url' LIMIT 1");
        $stmt->execute();
        $url = trim($stmt->fetchColumn() ?: '');
        return $url !== '' ? rtrim($url, '/') : 'http://ollama:11434';
    } catch (Exception $e) {
        return 'http://ollama:11434';
    }
}

function getProvidersConfig(): array {
    return [
        'anthropic' => [
            'label'           => 'Anthropic (Claude)',
            'icon'            => 'bi-robot',
            'color'           => 'warning',
            'key_label'       => 'Clé API Anthropic',
            'key_placeholder' => 'sk-ant-...',
            'key_help'        => 'Obtenir sur <a href="https://console.anthropic.com/keys" target="_blank" rel="noopener">console.anthropic.com</a>',
            'local'           => false,
            'models'          => [
                'claude-opus-4-7'           => 'Claude Opus 4.7 (Plus puissant)',
                'claude-sonnet-4-20250514'  => 'Claude Sonnet 4 (Recommandé)',
                'claude-haiku-4-5-20251001' => 'Claude Haiku (Plus rapide)',
            ],
            'default_model'   => 'claude-sonnet-4-20250514',
        ],
        'openai' => [
            'label'           => 'OpenAI (GPT)',
            'icon'            => 'bi-stars',
            'color'           => 'success',
            'key_label'       => 'Clé API OpenAI',
            'key_placeholder' => 'sk-...',
            'key_help'        => 'Obtenir sur <a href="https://platform.openai.com/api-keys" target="_blank" rel="noopener">platform.openai.com</a>',
            'local'           => false,
            'models'          => [
                'gpt-4o'      => 'GPT-4o (Recommandé)',
                'gpt-4o-mini' => 'GPT-4o Mini (Rapide, moins cher)',
                'gpt-4-turbo' => 'GPT-4 Turbo',
            ],
            'default_model'   => 'gpt-4o',
        ],
        'google' => [
            'label'           => 'Google (Gemini)',
            'icon'            => 'bi-google',
            'color'           => 'info',
            'key_label'       => 'Clé API Google AI',
            'key_placeholder' => 'AIza...',
            'key_help'        => 'Obtenir sur <a href="https://aistudio.google.com/app/apikey" target="_blank" rel="noopener">Google AI Studio</a>',
            'local'           => false,
            'models'          => [
                'gemini-2.0-flash' => 'Gemini 2.0 Flash (Rapide)',
                'gemini-1.5-pro'   => 'Gemini 1.5 Pro (Puissant)',
                'gemini-1.5-flash' => 'Gemini 1.5 Flash (Économique)',
            ],
            'default_model'   => 'gemini-2.0-flash',
        ],
        'ollama' => [
            'label'           => 'Ollama (Local)',
            'icon'            => 'bi-cpu',
            'color'           => 'secondary',
            'key_label'       => 'URL du serveur Ollama',
            'key_placeholder' => 'http://ollama:11434',
            'key_help'        => 'Adresse du serveur Ollama — <code>http://ollama:11434</code> dans Docker, <code>http://localhost:11434</code> en local',
            'local'           => true,
            'models'          => [
                'llama3.2:latest'  => 'Llama 3.2 (Recommandé)',
                'llama3.1:latest'  => 'Llama 3.1',
                'mistral:latest'   => 'Mistral 7B',
                'qwen2.5:latest'   => 'Qwen 2.5',
                'phi3:latest'      => 'Phi-3 Mini',
            ],
            'default_model'   => 'llama3.2:latest',
        ],
    ];
}

/**
 * Point d'entrée unifié — route vers le bon fournisseur.
 * Retourne ['success' => bool, 'text' => string, 'error' => string]
 */
function callAIUnified(
    string $provider,
    string $apiKey,
    string $model,
    string $systemPrompt,
    array  $messages,
    int    $maxTokens = 400
): array {
    return match($provider) {
        'openai' => _callOpenAI($apiKey, $model, $systemPrompt, $messages, $maxTokens),
        'google' => _callGemini($apiKey, $model, $systemPrompt, $messages, $maxTokens),
        'ollama' => _callOllama($model, $systemPrompt, $messages, $maxTokens),
        default  => _callAnthropic($apiKey, $model, $systemPrompt, $messages, $maxTokens),
    };
}

function _callAnthropic(string $apiKey, string $model, string $systemPrompt, array $messages, int $maxTokens): array {
    $payload = ['model' => $model, 'max_tokens' => $maxTokens, 'messages' => $messages];
    if ($systemPrompt !== '') {
        $payload['system'] = $systemPrompt;
    }

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'x-api-key: ' . $apiKey,
            'anthropic-version: 2023-06-01',
            'anthropic-beta: pdfs-2024-09-25',
        ],
        CURLOPT_TIMEOUT        => 120,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr)          return ['success' => false, 'error' => "Erreur réseau : $curlErr", 'text' => ''];
    if ($httpCode !== 200) {
        $errData = json_decode($response, true);
        $errMsg  = $errData['error']['message'] ?? "HTTP $httpCode";
        return ['success' => false, 'error' => "Erreur API Anthropic : $errMsg", 'text' => ''];
    }

    $data = json_decode($response, true);
    $text = $data['content'][0]['text'] ?? '';
    if ($text === '') return ['success' => false, 'error' => 'Réponse Anthropic invalide ou vide', 'text' => ''];
    return ['success' => true, 'text' => $text, 'error' => ''];
}

function _callOpenAI(string $apiKey, string $model, string $systemPrompt, array $messages, int $maxTokens, string $baseUrl = 'https://api.openai.com'): array {
    $oaiMessages = [];
    if ($systemPrompt !== '') {
        $oaiMessages[] = ['role' => 'system', 'content' => $systemPrompt];
    }
    foreach ($messages as $msg) {
        $content = $msg['content'];
        // Aplatir les blocs multi-parties (ex: document PDF) en texte simple
        if (is_array($content)) {
            $text = '';
            foreach ($content as $part) {
                if (isset($part['type']) && $part['type'] === 'text') {
                    $text .= $part['text'];
                }
            }
            $content = $text;
        }
        $oaiMessages[] = ['role' => $msg['role'], 'content' => $content];
    }

    $ch = curl_init(rtrim($baseUrl, '/') . '/v1/chat/completions');
    $headers = ['Content-Type: application/json'];
    if ($apiKey !== '') {
        $headers[] = 'Authorization: Bearer ' . $apiKey;
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode(['model' => $model, 'max_tokens' => $maxTokens, 'messages' => $oaiMessages]),
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 120,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr)          return ['success' => false, 'error' => "Erreur réseau : $curlErr", 'text' => ''];
    if ($httpCode !== 200) {
        $errData = json_decode($response, true);
        $errMsg  = $errData['error']['message'] ?? "HTTP $httpCode";
        return ['success' => false, 'error' => "Erreur API OpenAI : $errMsg", 'text' => ''];
    }

    $data = json_decode($response, true);
    $text = $data['choices'][0]['message']['content'] ?? '';
    if ($text === '') return ['success' => false, 'error' => 'Réponse OpenAI invalide ou vide', 'text' => ''];
    return ['success' => true, 'text' => $text, 'error' => ''];
}

function _callGemini(string $apiKey, string $model, string $systemPrompt, array $messages, int $maxTokens): array {
    $geminiContents = [];
    foreach ($messages as $msg) {
        $content = $msg['content'];
        if (is_array($content)) {
            $text = '';
            foreach ($content as $part) {
                if (isset($part['type']) && $part['type'] === 'text') {
                    $text .= $part['text'];
                }
            }
            $content = $text;
        }
        $role = $msg['role'] === 'assistant' ? 'model' : 'user';
        $geminiContents[] = ['role' => $role, 'parts' => [['text' => $content]]];
    }

    $payload = [
        'contents'         => $geminiContents,
        'generationConfig' => ['maxOutputTokens' => $maxTokens],
    ];
    if ($systemPrompt !== '') {
        $payload['systemInstruction'] = ['parts' => [['text' => $systemPrompt]]];
    }

    $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 120,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr)          return ['success' => false, 'error' => "Erreur réseau : $curlErr", 'text' => ''];
    if ($httpCode !== 200) {
        $errData = json_decode($response, true);
        $errMsg  = $errData['error']['message'] ?? "HTTP $httpCode";
        return ['success' => false, 'error' => "Erreur API Google : $errMsg", 'text' => ''];
    }

    $data = json_decode($response, true);
    $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
    if ($text === '') return ['success' => false, 'error' => 'Réponse Google invalide ou vide', 'text' => ''];
    return ['success' => true, 'text' => $text, 'error' => ''];
}

/**
 * Appel Ollama — API compatible OpenAI, pas de clé requise.
 * Utilise l'URL configurée dans la table config (ollama_base_url).
 */
function _callOllama(string $model, string $systemPrompt, array $messages, int $maxTokens): array {
    $baseUrl = getOllamaBaseUrl();

    // Test de connectivité rapide
    $chTest = curl_init($baseUrl . '/api/tags');
    curl_setopt_array($chTest, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5, CURLOPT_SSL_VERIFYPEER => false]);
    curl_exec($chTest);
    $testCode = curl_getinfo($chTest, CURLINFO_HTTP_CODE);
    $testErr  = curl_error($chTest);
    curl_close($chTest);

    if ($testErr || $testCode === 0) {
        return ['success' => false, 'error' => "Ollama inaccessible à $baseUrl — vérifiez que le container eval_ollama est démarré", 'text' => ''];
    }

    // Appel via l'API OpenAI-compatible d'Ollama
    return _callOpenAI('', $model, $systemPrompt, $messages, $maxTokens, $baseUrl);
}
