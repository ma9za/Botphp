<?php

declare(strict_types=1);

require_once __DIR__ . '/functions.php';

function handleOrchestration(array $message): void
{
    $chatId = (int) ($message['chat']['id'] ?? 0);
    $text = trim((string) ($message['text'] ?? ''));
    $fromId = (int) ($message['from']['id'] ?? 0);

    // Only process messages from bots in the designated conversation group
    // This logic needs to be refined to identify the conversation group and participating bots
    // For now, let's assume any message in a group where bots are present triggers orchestration

    // Prevent bots from responding to themselves or other bots directly
    // The orchestrator will manage turns
    if (isBot($fromId)) {
        return; // Bot messages are handled by the orchestrator, not by other bots directly
    }

    // For demonstration, let's assume a single conversation group and two bots
    // In a real scenario, this would be dynamic based on configuration
    $conversationGroupId = getSetting('conversation_group_id'); // Needs to be set in config
    if ($chatId !== (int)$conversationGroupId) {
        return; // Not the designated conversation group
    }

    // Get current conversation state (e.g., who spoke last, whose turn it is)
    $conversationState = getConversationState($chatId);

    // Determine the next bot to speak
    $nextBotId = determineNextBot($conversationState);
    if ($nextBotId === null) {
        // No bot configured to speak, or an error occurred
        return;
    }

    // Get conversation history for the AI
    $history = getConversationHistory($chatId, $nextBotId);

    // Add the current user message to the history for context
    $history[] = ['role' => 'user', 'content' => $text];

    // Call AI API to get a response for the next bot
    $aiResponse = callAiApi($history, $nextBotId);

    if ($aiResponse['ok']) {
        $botResponseText = $aiResponse['response']['choices'][0]['message']['content'] ?? 'لم أتمكن من توليد رد.';

        // Send the AI response via the next bot
        $botToken = getBotTokenById($nextBotId); // Needs to be implemented
        if ($botToken) {
            sendTelegramMessage($botToken, $chatId, $botResponseText);
            // Store the bot's response in history
            addMessageToConversationHistory($chatId, $nextBotId, $botResponseText, 'assistant');
            // Update conversation state (e.g., last speaker)
            updateConversationState($chatId, $nextBotId);
        }
    } else {
        // Log error or send a message to admin
        error_log('AI API call failed: ' . ($aiResponse['description'] ?? 'Unknown error'));
    }
}

function isBot(int $userId): bool
{
    $botIds = getBotIds();
    return in_array($userId, $botIds, true);
}

function getBotIds(): array
{
    $pdo = db();
    $stmt = $pdo->query("SELECT bot_id FROM bots_config");
    return array_column($stmt->fetchAll(), 'bot_id');
}

function getBotTokenById(int $botId): ?string
{
    $pdo = db();
    $stmt = $pdo->prepare("SELECT bot_token FROM bots_config WHERE bot_id = :bot_id");
    $stmt->execute([':bot_id' => $botId]);
    $result = $stmt->fetch();
    return $result["bot_token"] ?? null;
}

function getConversationState(int $chatId): array
{
    $pdo = db();
    $stmt = $pdo->prepare("SELECT last_speaker_bot_id, turn_order FROM conversation_state WHERE chat_id = :chat_id");
    $stmt->execute([":chat_id" => $chatId]);
    $state = $stmt->fetch();

    if ($state === false) {
        // Initialize state if not found
        $botIds = getBotIds();
        $initialTurnOrder = json_encode($botIds);
        $initialLastSpeaker = !empty($botIds) ? $botIds[0] : null;

        $stmt = $pdo->prepare("INSERT INTO conversation_state (chat_id, last_speaker_bot_id, turn_order, updated_at) VALUES (:chat_id, :last_speaker_bot_id, :turn_order, :updated_at)");
        $stmt->execute([
            ":chat_id" => $chatId,
            ":last_speaker_bot_id" => $initialLastSpeaker,
            ":turn_order" => $initialTurnOrder,
            ":updated_at" => time(),
        ]);
        return [
            "last_speaker_bot_id" => $initialLastSpeaker,
            "turn_order" => $botIds,
        ];
    }

    return [
        "last_speaker_bot_id" => (int) $state["last_speaker_bot_id"],
        "turn_order" => json_decode($state["turn_order"], true),
    ];
}

function updateConversationState(int $chatId, int $lastSpeakerId): void
{
    $pdo = db();
    $stmt = $pdo->prepare("UPDATE conversation_state SET last_speaker_bot_id = :last_speaker_bot_id, updated_at = :updated_at WHERE chat_id = :chat_id");
    $stmt->execute([
        ":last_speaker_bot_id" => $lastSpeakerId,
        ":updated_at" => time(),
        ":chat_id" => $chatId,
    ]);
}

function determineNextBot(array $conversationState): ?int
{
    $lastSpeakerId = $conversationState['last_speaker_id'];
    $turnOrder = $conversationState['turn_order'];

    $currentIndex = array_search($lastSpeakerId, $turnOrder, true);
    if ($currentIndex === false || !isset($turnOrder[$currentIndex + 1])) {
        // If last speaker was the last in turn order, start from the beginning
        return $turnOrder[0] ?? null;
    }

    return $turnOrder[$currentIndex + 1];
}

function getConversationHistory(int $chatId, int $botId, int $limit = 10): array
{
    $pdo = db();
    $stmt = $pdo->prepare("SELECT role, content FROM conversation_history WHERE chat_id = :chat_id AND bot_id = :bot_id ORDER BY timestamp DESC LIMIT :limit");
    $stmt->bindValue(":chat_id", $chatId, PDO::PARAM_INT);
    $stmt->bindValue(":bot_id", $botId, PDO::PARAM_INT);
    $stmt->bindValue(":limit", $limit, PDO::PARAM_INT);
    $stmt->execute();
    $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
    return array_reverse($history); // Return in chronological order
}

function addMessageToConversationHistory(int $chatId, int $botId, string $text, string $role): void
{
    $pdo = db();
    $stmt = $pdo->prepare("INSERT INTO conversation_history (chat_id, bot_id, role, content, timestamp) VALUES (:chat_id, :bot_id, :role, :content, :timestamp)");
    $stmt->execute([
        ":chat_id" => $chatId,
        ":bot_id" => $botId,
        ":role" => $role,
        ":content" => $text,
        ":timestamp" => time(),
    ]);
}

function callAiApi(array $history, int $botId): array
{
    // For now, only DeepSeek is integrated. Extend this to choose between DeepSeek and Pollinations.ai
    return deepseekApiCall($history);
}

function sendTelegramMessage(string $botToken, int $chatId, string $text): array
{
    // This function will use the provided botToken to send a message
    // It's a wrapper around the existing telegramApi function, but with a dynamic token
    $opts = [
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content' => http_build_query(['chat_id' => $chatId, 'text' => $text, 'parse_mode' => 'HTML']),
            'timeout' => 25,
        ],
    ];

    $res = @file_get_contents('https://api.telegram.org/bot' . $botToken . '/sendMessage', false, stream_context_create($opts));
    if ($res === false) {
        return ['ok' => false, 'description' => 'Request failed'];
    }
    $decoded = json_decode($res, true);
    return is_array($decoded) ? $decoded : ['ok' => false, 'description' => 'Invalid response'];
}
