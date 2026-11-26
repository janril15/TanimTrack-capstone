<?php
declare(strict_types=1);

require __DIR__ . '/common.php';

function chat_valid_uuid(mixed $value): bool
{
    return is_string($value)
        && preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/iD', $value) === 1;
}

function chat_list_plants(PDO $pdo, int $userId): array
{
    $statement = $pdo->prepare(
        'SELECT id, name, species, planted_on
         FROM plants WHERE user_id = :user_id ORDER BY updated_at DESC, id DESC LIMIT 50'
    );
    $statement->execute(['user_id' => $userId]);
    return array_map(static fn (array $row): array => [
        'id' => (string) $row['id'],
        'name' => (string) $row['name'],
        'species' => $row['species'] !== null ? (string) $row['species'] : '',
        'planted_on' => $row['planted_on'] !== null ? (string) $row['planted_on'] : null,
    ], $statement->fetchAll());
}

function chat_list_conversations(PDO $pdo, int $userId): array
{
    $statement = $pdo->prepare(
        'SELECT conversations.id, conversations.title, conversations.plant_id,
                plants.name AS plant_name, conversations.updated_at
         FROM ai_conversations AS conversations
         LEFT JOIN plants
           ON plants.id = conversations.plant_id AND plants.user_id = conversations.user_id
         WHERE conversations.user_id = :user_id
         ORDER BY conversations.updated_at DESC, conversations.id DESC
         LIMIT 50'
    );
    $statement->execute(['user_id' => $userId]);
    return array_map(static fn (array $row): array => [
        'id' => (string) $row['id'],
        'title' => (string) $row['title'],
        'plant_id' => $row['plant_id'] !== null ? (string) $row['plant_id'] : null,
        'plant_name' => $row['plant_name'] !== null ? (string) $row['plant_name'] : null,
        'updated_at' => (string) $row['updated_at'],
    ], $statement->fetchAll());
}

function chat_is_personal_or_gardening_question(string $message): bool
{
    return preg_match(
        '/\b(my|mine|our|this|that|these|those|it|its|plant|crop|seed|soil|water|watering|fertili[sz]er|compost|sunlight|shade|grow|growth|stage|health|scan|progress|harvest|disease|pest|leaf|leaves|yellow|wilting|spots|roots?|halaman|tanim|punla|dahon|dilaw|naninilaw|lanta|nalalanta|dilig|pataba|lupa|sakit|uod|ani|tubo|yugto|alaga|aking|akin|ko|natin|namin|niya|nila|kumusta|kamusta|lumalaki|lumalago)\b/iu',
        $message
    ) === 1;
}

function chat_build_plant_context(PDO $pdo, int $userId, ?string $selectedPlantId, string $message): array
{
    if ($selectedPlantId !== null) {
        $statement = $pdo->prepare(
            'SELECT id, name, species, planted_on FROM plants WHERE id = :id AND user_id = :user_id LIMIT 1'
        );
        $statement->execute(['id' => $selectedPlantId, 'user_id' => $userId]);
        $selectedPlant = $statement->fetch();
        $plantRows = $selectedPlant === false ? [] : [[
            'id' => (string) $selectedPlant['id'],
            'name' => (string) $selectedPlant['name'],
            'species' => $selectedPlant['species'] !== null ? (string) $selectedPlant['species'] : '',
            'planted_on' => $selectedPlant['planted_on'] !== null ? (string) $selectedPlant['planted_on'] : null,
        ]];
    } else {
        $plantRows = chat_list_plants($pdo, $userId);
    }
    if ($plantRows === []) {
        return [null, null];
    }

    $lowerMessage = mb_strtolower($message, 'UTF-8');
    $matchedPlants = [];
    if ($selectedPlantId !== null) {
        foreach ($plantRows as $plant) {
            if ($plant['id'] === $selectedPlantId) {
                $matchedPlants[] = $plant;
                break;
            }
        }
    } else {
        foreach ($plantRows as $plant) {
            foreach ([$plant['name'], $plant['species']] as $label) {
                $label = mb_strtolower(trim($label), 'UTF-8');
                if ($label !== '' && mb_stripos($lowerMessage, $label, 0, 'UTF-8') !== false) {
                    $matchedPlants[] = $plant;
                    break;
                }
            }
        }
        if (
            $matchedPlants === []
            && preg_match('/\b(my plants|our plants|these plants|all my plants|their progress)\b/iu', $message) === 1
        ) {
            $matchedPlants = array_slice($plantRows, 0, 3);
        }
    }

    if ($matchedPlants === [] || !chat_is_personal_or_gardening_question($message)) {
        return [null, $selectedPlantId];
    }

    $context = [];
    $scanStatement = $pdo->prepare(
        'SELECT id, created_at, plant_name, health_status, detected_issue, severity,
                leaf_count, plant_size_cm, plant_height_cm, analysis_result
         FROM plant_analysis_history
         WHERE user_id = :user_id AND plant_id = :plant_id
         ORDER BY created_at DESC, id DESC
         LIMIT 2'
    );
    $stageStatement = $pdo->prepare(
        'SELECT growth_stage, activity_date
         FROM farm_activities
         WHERE user_id = :user_id AND plant_id = :plant_id
           AND activity_type = \'Plant Growth\'
           AND growth_stage IS NOT NULL AND growth_stage <> \'\'
         ORDER BY activity_date DESC, created_at DESC, id DESC
         LIMIT 1'
    );

    foreach (array_slice($matchedPlants, 0, 3) as $plant) {
        $lines = [
            'Plant name: ' . preg_replace('/[\r\n\t]+/u', ' ', $plant['name']),
        ];
        if ($plant['species'] !== '') {
            $lines[] = 'Recorded species: ' . preg_replace('/[\r\n\t]+/u', ' ', $plant['species']);
        }
        if ($plant['planted_on'] !== null) {
            $lines[] = 'Recorded planting date: ' . $plant['planted_on'];
        }

        $scanStatement->execute(['user_id' => $userId, 'plant_id' => $plant['id']]);
        $scans = $scanStatement->fetchAll();
        if ($scans === []) {
            $lines[] = 'No saved scan is recorded for this plant.';
        } else {
            foreach ($scans as $index => $scan) {
                $label = $index === 0 ? 'Latest saved scan' : 'Previous saved scan';
                $parts = [
                    $label . ' date: ' . (string) $scan['created_at'],
                    'status: ' . (string) $scan['health_status'],
                ];
                if (is_string($scan['plant_name']) && $scan['plant_name'] !== '') {
                    $parts[] = 'scan identified: ' . $scan['plant_name'];
                }
                if (is_string($scan['detected_issue']) && $scan['detected_issue'] !== '') {
                    $parts[] = 'recorded issue: ' . $scan['detected_issue'];
                }
                if (is_string($scan['severity']) && $scan['severity'] !== '') {
                    $parts[] = 'severity: ' . $scan['severity'];
                }
                foreach ([
                    'leaf_count' => 'recorded leaf count',
                    'plant_size_cm' => 'recorded plant size (cm)',
                    'plant_height_cm' => 'recorded plant height (cm)',
                ] as $field => $label) {
                    if ($scan[$field] !== null) {
                        $parts[] = $label . ': ' . (string) $scan[$field];
                    }
                }
                $result = is_string($scan['analysis_result'])
                    ? json_decode($scan['analysis_result'], true)
                    : null;
                if (is_array($result)) {
                    $analysis = $result['analysis'] ?? null;
                    if (is_string($analysis) && $analysis !== '') {
                        $parts[] = 'scan notes: ' . mb_substr(preg_replace('/[\r\n\t]+/u', ' ', $analysis), 0, 350, 'UTF-8');
                    }
                    foreach (['visible_symptoms', 'recommendations', 'prevention_tips'] as $field) {
                        $items = $result[$field] ?? null;
                        if (is_array($items)) {
                            $items = array_slice(array_filter($items, 'is_string'), 0, 3);
                            if ($items !== []) {
                                $parts[] = str_replace('_', ' ', $field) . ': '
                                    . implode('; ', array_map(
                                        static fn (string $item): string => mb_substr(preg_replace('/[\r\n\t]+/u', ' ', $item), 0, 180, 'UTF-8'),
                                        $items
                                    ));
                            }
                        }
                    }
                }
                $lines[] = implode(', ', $parts) . '.';
            }
        }

        $stageStatement->execute(['user_id' => $userId, 'plant_id' => $plant['id']]);
        $stage = $stageStatement->fetch();
        if ($stage !== false) {
            $lines[] = 'Recorded growth stage: ' . (string) $stage['growth_stage']
                . ' (logged ' . (string) $stage['activity_date'] . ').';
        }
        $context[] = implode("\n", $lines);
    }

    return [implode("\n\n", $context), $matchedPlants[0]['id']];
}

$method = $_SERVER['REQUEST_METHOD'] ?? '';
if (!in_array($method, ['GET', 'POST', 'DELETE'], true)) {
    header('Allow: GET, POST, DELETE');
    json_response(['success' => false, 'message' => 'Method not allowed.'], 405);
}

start_app_session();
$sessionUser = session_user();
if ($sessionUser === null) {
    json_response(['success' => false, 'message' => 'Sign in to use TanimTrack AI.'], 401);
}

$pdo = pdo_connection();
$userId = $sessionUser['id'];

if ($method === 'GET') {
    $conversationId = $_GET['conversation_id'] ?? null;
    if ($conversationId !== null && !chat_valid_uuid($conversationId)) {
        json_response(['success' => false, 'message' => 'Choose a valid conversation.'], 422);
    }

    try {
        $conversation = null;
        $messages = [];
        if (is_string($conversationId)) {
            $statement = $pdo->prepare(
                'SELECT id, title, plant_id, updated_at
                 FROM ai_conversations WHERE id = :id AND user_id = :user_id LIMIT 1'
            );
            $statement->execute(['id' => $conversationId, 'user_id' => $userId]);
            $conversation = $statement->fetch();
            if ($conversation === false) {
                json_response(['success' => false, 'message' => 'That conversation could not be found.'], 404);
            }

            $statement = $pdo->prepare(
                'SELECT `role`, content, created_at FROM ai_chat_messages
                 WHERE conversation_id = :conversation_id AND user_id = :user_id
                 ORDER BY id DESC LIMIT 100'
            );
            $statement->execute(['conversation_id' => $conversationId, 'user_id' => $userId]);
            $messages = array_reverse(array_map(static fn (array $row): array => [
                'role' => (string) $row['role'],
                'content' => (string) $row['content'],
                'created_at' => (string) $row['created_at'],
            ], $statement->fetchAll()));
            $conversation = [
                'id' => (string) $conversation['id'],
                'title' => (string) $conversation['title'],
                'plant_id' => $conversation['plant_id'] !== null ? (string) $conversation['plant_id'] : null,
                'updated_at' => (string) $conversation['updated_at'],
            ];
        }

        json_response([
            'success' => true,
            'conversations' => chat_list_conversations($pdo, $userId),
            'plants' => chat_list_plants($pdo, $userId),
            'conversation' => $conversation,
            'messages' => $messages,
        ]);
    } catch (PDOException $error) {
        error_log('TanimTrack chat history could not be loaded: ' . $error->getMessage());
        json_response(['success' => false, 'message' => 'TanimTrack AI history is temporarily unavailable.'], 503);
    }
}

if ($method === 'DELETE') {
    $conversationId = $_GET['conversation_id'] ?? null;
    if (!chat_valid_uuid($conversationId)) {
        json_response(['success' => false, 'message' => 'Choose a valid conversation to clear.'], 422);
    }
    try {
        $statement = $pdo->prepare(
            'DELETE FROM ai_conversations WHERE id = :id AND user_id = :user_id'
        );
        $statement->execute(['id' => $conversationId, 'user_id' => $userId]);
        if ($statement->rowCount() === 0) {
            json_response(['success' => false, 'message' => 'That conversation could not be found.'], 404);
        }
        json_response(['success' => true]);
    } catch (PDOException $error) {
        error_log('TanimTrack chat conversation could not be cleared: ' . $error->getMessage());
        json_response(['success' => false, 'message' => 'The conversation could not be cleared. Please try again.'], 503);
    }
}

$data = read_json_request();
$message = is_string($data['message'] ?? null) ? trim($data['message']) : '';
if ($message === '' || !mb_check_encoding($message, 'UTF-8')) {
    json_response(['success' => false, 'message' => 'Enter a question for TanimTrack AI.'], 422);
}
if (mb_strlen($message, 'UTF-8') > 2000) {
    json_response(['success' => false, 'message' => 'Keep your question under 2,000 characters.'], 422);
}

$conversationId = $data['conversation_id'] ?? null;
if ($conversationId !== null && !chat_valid_uuid($conversationId)) {
    json_response(['success' => false, 'message' => 'Choose a valid conversation.'], 422);
}

$hasPlantId = array_key_exists('plant_id', $data);
$requestedPlantId = $data['plant_id'] ?? null;
if ($hasPlantId && $requestedPlantId !== null && !chat_valid_uuid($requestedPlantId)) {
    json_response(['success' => false, 'message' => 'Choose a valid plant from your account.'], 422);
}
$requestedPlantId = is_string($requestedPlantId) ? strtolower($requestedPlantId) : null;

try {
    $existingConversation = null;
    if (is_string($conversationId)) {
        $statement = $pdo->prepare(
            'SELECT id, title, plant_id FROM ai_conversations
             WHERE id = :id AND user_id = :user_id LIMIT 1'
        );
        $statement->execute(['id' => $conversationId, 'user_id' => $userId]);
        $existingConversation = $statement->fetch();
        if ($existingConversation === false) {
            json_response(['success' => false, 'message' => 'That conversation could not be found.'], 404);
        }
    }

    $plantId = $hasPlantId
        ? $requestedPlantId
        : (is_array($existingConversation) && $existingConversation['plant_id'] !== null
            ? (string) $existingConversation['plant_id']
            : null);
    if ($plantId !== null) {
        $statement = $pdo->prepare('SELECT id FROM plants WHERE id = :id AND user_id = :user_id LIMIT 1');
        $statement->execute(['id' => $plantId, 'user_id' => $userId]);
        if ($statement->fetchColumn() === false) {
            json_response(['success' => false, 'message' => 'That plant could not be found in your account.'], 404);
        }
    }

    $history = [];
    if (is_string($conversationId)) {
        $statement = $pdo->prepare(
            'SELECT `role`, content FROM ai_chat_messages
             WHERE conversation_id = :conversation_id AND user_id = :user_id
             ORDER BY id DESC LIMIT 20'
        );
        $statement->execute(['conversation_id' => $conversationId, 'user_id' => $userId]);
        $history = array_reverse(array_map(static fn (array $row): array => [
            'role' => (string) $row['role'],
            'content' => (string) $row['content'],
        ], $statement->fetchAll()));
    }

    $statement = $pdo->prepare(
        'SELECT COUNT(*) FROM ai_chat_messages
         WHERE user_id = :user_id AND `role` = \'user\'
           AND created_at >= UTC_TIMESTAMP() - INTERVAL 1 MINUTE'
    );
    $statement->execute(['user_id' => $userId]);
    if ((int) $statement->fetchColumn() >= 8) {
        json_response(['success' => false, 'message' => 'You are sending questions too quickly. Wait a minute and try again.'], 429);
    }

    [$plantContext, $resolvedPlantId] = chat_build_plant_context($pdo, $userId, $plantId, $message);
    if ($resolvedPlantId !== null) {
        $plantId = $resolvedPlantId;
    }
} catch (PDOException $error) {
    error_log('TanimTrack chat context lookup failed: ' . $error->getMessage());
    json_response(['success' => false, 'message' => 'TanimTrack AI could not load your conversation context. Please retry.'], 503);
}

$aiUrl = getenv('TANODBUKID_AI_CHAT_URL') ?: 'http://ai:8080/chat';
$request = curl_init($aiUrl);
if ($request === false) {
    error_log('TanimTrack chat could not initialize its AI request.');
    json_response(['success' => false, 'message' => 'TanimTrack AI is temporarily unavailable.'], 503);
}

try {
    $requestBody = json_encode([
        'message' => $message,
        'history' => $history,
        'plant_context' => $plantContext,
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (JsonException $error) {
    curl_close($request);
    error_log('TanimTrack chat request could not be encoded.');
    json_response(['success' => false, 'message' => 'Your question could not be processed. Please retry.'], 422);
}

curl_setopt_array($request, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $requestBody,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_TIMEOUT => 100,
    CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/json'],
]);
$responseBody = curl_exec($request);
$status = (int) curl_getinfo($request, CURLINFO_RESPONSE_CODE);
$curlErrorNumber = curl_errno($request);
$curlError = curl_error($request);
curl_close($request);

if (!is_string($responseBody)) {
    if ($curlErrorNumber === CURLE_OPERATION_TIMEDOUT) {
        error_log('TanimTrack chat request timed out.');
        json_response(['success' => false, 'message' => 'TanimTrack AI timed out. Please retry.'], 504);
    }
    error_log('TanimTrack chat request failed: ' . ($curlError !== '' ? $curlError : 'no response'));
    json_response(['success' => false, 'message' => 'TanimTrack AI is temporarily unavailable. Please retry.'], 503);
}

$aiResult = json_decode($responseBody, true);
if ($status < 200 || $status >= 300) {
    $upstreamMessage = is_array($aiResult)
        ? (string) ($aiResult['message'] ?? $aiResult['detail'] ?? '')
        : '';
    $statusMessages = [
        422 => [422, 'Enter a valid question for TanimTrack AI.'],
        429 => [429, 'TanimTrack AI is busy. Please wait a moment and retry.'],
        504 => [504, 'TanimTrack AI timed out. Please retry.'],
    ];
    if (isset($statusMessages[$status])) {
        [$responseStatus, $errorMessage] = $statusMessages[$status];
        json_response(['success' => false, 'message' => $errorMessage], $responseStatus);
    }
    if ($status === 503 && str_contains(mb_strtolower($upstreamMessage, 'UTF-8'), 'not configured')) {
        json_response(['success' => false, 'message' => 'TanimTrack AI is not configured. Add the Gemini key to the private environment file.'], 503);
    }
    error_log('TanimTrack chat AI request failed with HTTP status ' . $status . '.');
    json_response(['success' => false, 'message' => 'TanimTrack AI is temporarily unavailable. Please retry.'], 503);
}

if (
    !is_array($aiResult)
    || ($aiResult['success'] ?? false) !== true
    || !is_string($aiResult['answer'] ?? null)
    || trim($aiResult['answer']) === ''
    || mb_strlen($aiResult['answer'], 'UTF-8') > 5000
) {
    error_log('TanimTrack chat AI returned an invalid response.');
    json_response(['success' => false, 'message' => 'TanimTrack AI returned an invalid answer. Please retry.'], 502);
}
$answer = trim($aiResult['answer']);

$title = is_array($existingConversation)
    ? (string) $existingConversation['title']
    : mb_substr(preg_replace('/\s+/u', ' ', $message), 0, 120, 'UTF-8');
$conversationId = is_array($existingConversation)
    ? (string) $existingConversation['id']
    : app_uuid_v4();

try {
    $pdo->beginTransaction();
    if (!is_array($existingConversation)) {
        $statement = $pdo->prepare(
            'INSERT INTO ai_conversations (id, user_id, plant_id, title)
             VALUES (:id, :user_id, :plant_id, :title)'
        );
        $statement->execute([
            'id' => $conversationId,
            'user_id' => $userId,
            'plant_id' => $plantId,
            'title' => $title,
        ]);
    } else {
        $statement = $pdo->prepare(
            'UPDATE ai_conversations SET plant_id = :plant_id, updated_at = CURRENT_TIMESTAMP
             WHERE id = :id AND user_id = :user_id'
        );
        $statement->execute([
            'plant_id' => $plantId,
            'id' => $conversationId,
            'user_id' => $userId,
        ]);
    }

    $statement = $pdo->prepare(
        'INSERT INTO ai_chat_messages (user_id, conversation_id, `role`, content)
         VALUES (:user_id, :conversation_id, :role, :content)'
    );
    $statement->execute([
        'user_id' => $userId,
        'conversation_id' => $conversationId,
        'role' => 'user',
        'content' => $message,
    ]);
    $statement->execute([
        'user_id' => $userId,
        'conversation_id' => $conversationId,
        'role' => 'assistant',
        'content' => $answer,
    ]);
    $pdo->commit();
} catch (PDOException $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('TanimTrack chat response could not be saved: ' . $error->getMessage());
    json_response(['success' => false, 'message' => 'The answer was generated but could not be saved. Please retry.'], 503);
}

json_response([
    'success' => true,
    'conversation' => [
        'id' => $conversationId,
        'title' => $title,
        'plant_id' => $plantId,
    ],
    'user_message' => $message,
    'answer' => $answer,
]);
