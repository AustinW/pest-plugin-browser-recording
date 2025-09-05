<?php

declare(strict_types=1);

namespace PestPluginBrowserRecording\Recorder;

use InvalidArgumentException;
use JsonException;

/**
 * Records and structures user actions during a browser session
 * 
 * This class provides a structured way to capture, validate, and store
 * user actions for later code generation. It maintains session isolation
 * and ensures thread safety for concurrent recording sessions.
 */
final class ActionRecorder
{
    /**
     * Maximum number of actions to store per session (prevent memory issues)
     */
    private const MAX_ACTIONS_PER_SESSION = 10000;

    /**
     * Supported action types and their required fields
     */
    private const ACTION_SCHEMAS = [
        'click' => ['selector', 'coordinates'],
        'dblclick' => ['selector'],
        'rightclick' => ['selector'],
        'input' => ['selector', 'value', 'inputType'],
        'change' => ['selector', 'value'],
        'focus' => ['selector'],
        'blur' => ['selector'],
        'submit' => ['selector', 'data'],
        'keydown' => ['key', 'modifiers'],
        'scroll' => ['scrollX', 'scrollY'],
        'hover' => ['selector', 'action'],
        'navigation' => ['type', 'url'],
        'session:start' => ['sessionId', 'viewport', 'userAgent'],
        'session:end' => ['sessionId', 'totalActions'],
        'session:heartbeat' => [],
        'dom:added' => ['target', 'elements'],
        'visibility' => ['selector', 'visible'],
        'beforeunload' => ['url'],
        'communication:error' => ['error'],
    ];

    /**
     * @var array<string, array<ActionData>> Actions grouped by session ID
     */
    private array $sessionActions = [];

    /**
     * @var array<string, ActionMetadata> Session metadata by session ID
     */
    private array $sessionMetadata = [];

    /**
     * @var int Total number of actions recorded across all sessions
     */
    private int $totalActionsRecorded = 0;

    /**
     * Record a new action for a specific session
     * 
     * @param string $sessionId Unique session identifier
     * @param string $type Action type (click, input, etc.)
     * @param array<string, mixed> $data Action-specific data
     * @param array<string, mixed> $context Additional context (timestamp, url, etc.)
     * @throws InvalidArgumentException For invalid action data
     */
    public function recordAction(string $sessionId, string $type, array $data, array $context = []): void
    {
        // Validate session ID
        if (empty($sessionId)) {
            throw new InvalidArgumentException('Session ID cannot be empty');
        }

        // Validate action type
        if (!isset(self::ACTION_SCHEMAS[$type])) {
            throw new InvalidArgumentException("Unsupported action type: {$type}");
        }

        // Initialize session if it doesn't exist
        if (!isset($this->sessionActions[$sessionId])) {
            $this->initializeSession($sessionId);
        }

        // Check session action limit
        if (count($this->sessionActions[$sessionId]) >= self::MAX_ACTIONS_PER_SESSION) {
            throw new InvalidArgumentException("Session {$sessionId} has reached maximum action limit");
        }

        // Validate required fields for this action type
        $this->validateActionData($type, $data);

        // Create action data structure
        $actionData = new ActionData(
            type: $type,
            data: $this->sanitizeActionData($data),
            timestamp: $context['timestamp'] ?? time(),
            url: $context['url'] ?? '',
            sessionId: $sessionId,
            sequence: count($this->sessionActions[$sessionId]) + 1,
            viewport: $context['viewport'] ?? null,
            metadata: $context['metadata'] ?? []
        );

        // Store the action
        $this->sessionActions[$sessionId][] = $actionData;
        $this->totalActionsRecorded++;

        // Update session metadata
        $this->updateSessionMetadata($sessionId, $actionData);
    }

    /**
     * Get all actions for a specific session
     * 
     * @param string $sessionId Session identifier
     * @return array<ActionData> Array of actions in chronological order
     */
    public function getSessionActions(string $sessionId): array
    {
        return $this->sessionActions[$sessionId] ?? [];
    }

    /**
     * Get actions for a session within a specific time range
     * 
     * @param string $sessionId Session identifier
     * @param int $startTime Start timestamp (inclusive)
     * @param int $endTime End timestamp (inclusive)
     * @return array<ActionData> Filtered actions
     */
    public function getActionsInRange(string $sessionId, int $startTime, int $endTime): array
    {
        $actions = $this->getSessionActions($sessionId);
        
        $filtered = array_filter($actions, function (ActionData $action) use ($startTime, $endTime) {
            return $action->timestamp >= $startTime && $action->timestamp <= $endTime;
        });
        
        // Re-index the array to ensure consistent indexing
        return array_values($filtered);
    }

    /**
     * Get actions of specific types for a session
     * 
     * @param string $sessionId Session identifier
     * @param array<string> $types Action types to filter by
     * @return array<ActionData> Filtered actions
     */
    public function getActionsByTypes(string $sessionId, array $types): array
    {
        $actions = $this->getSessionActions($sessionId);
        
        $filtered = array_filter($actions, function (ActionData $action) use ($types) {
            return in_array($action->type, $types, true);
        });
        
        // Re-index the array to ensure consistent indexing
        return array_values($filtered);
    }

    /**
     * Get session metadata
     * 
     * @param string $sessionId Session identifier
     * @return ActionMetadata|null Session metadata or null if session doesn't exist
     */
    public function getSessionMetadata(string $sessionId): ?ActionMetadata
    {
        return $this->sessionMetadata[$sessionId] ?? null;
    }

    /**
     * Get all active session IDs
     * 
     * @return array<string> Array of session IDs
     */
    public function getActiveSessions(): array
    {
        return array_keys($this->sessionActions);
    }

    /**
     * Clear all actions for a specific session
     * 
     * @param string $sessionId Session identifier
     */
    public function clearSession(string $sessionId): void
    {
        if (isset($this->sessionActions[$sessionId])) {
            $actionCount = count($this->sessionActions[$sessionId]);
            unset($this->sessionActions[$sessionId]);
            unset($this->sessionMetadata[$sessionId]);
            $this->totalActionsRecorded -= $actionCount;
        }
    }

    /**
     * Clear all recorded actions and sessions
     */
    public function clearAll(): void
    {
        $this->sessionActions = [];
        $this->sessionMetadata = [];
        $this->totalActionsRecorded = 0;
    }

    /**
     * Export session actions to array format suitable for serialization
     * 
     * @param string $sessionId Session identifier
     * @return array<string, mixed> Exportable session data
     */
    public function exportSession(string $sessionId): array
    {
        $actions = $this->getSessionActions($sessionId);
        $metadata = $this->getSessionMetadata($sessionId);

        return [
            'sessionId' => $sessionId,
            'metadata' => $metadata?->toArray() ?? [],
            'actions' => array_map(fn(ActionData $action) => $action->toArray(), $actions),
            'totalActions' => count($actions),
            'exportedAt' => time(),
        ];
    }

    /**
     * Export session actions to JSON
     * 
     * @param string $sessionId Session identifier
     * @return string JSON representation of session data
     * @throws JsonException If JSON encoding fails
     */
    public function exportSessionToJson(string $sessionId): string
    {
        $data = $this->exportSession($sessionId);
        return json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
    }

    /**
     * Import session data from array
     * 
     * @param array<string, mixed> $sessionData Session data to import
     * @throws InvalidArgumentException If session data is invalid
     */
    public function importSession(array $sessionData): void
    {
        if (!isset($sessionData['sessionId']) || !is_string($sessionData['sessionId'])) {
            throw new InvalidArgumentException('Invalid session data: missing or invalid sessionId');
        }

        $sessionId = $sessionData['sessionId'];
        
        if (!isset($sessionData['actions']) || !is_array($sessionData['actions'])) {
            throw new InvalidArgumentException('Invalid session data: missing or invalid actions array');
        }

        // Clear existing session data
        $this->clearSession($sessionId);

        // Import metadata if present
        if (isset($sessionData['metadata']) && is_array($sessionData['metadata'])) {
            $this->sessionMetadata[$sessionId] = ActionMetadata::fromArray($sessionData['metadata']);
        }

        // Import actions
        $this->sessionActions[$sessionId] = [];
        foreach ($sessionData['actions'] as $actionArray) {
            if (!is_array($actionArray)) {
                continue;
            }

            try {
                $actionData = ActionData::fromArray($actionArray);
                $this->sessionActions[$sessionId][] = $actionData;
                $this->totalActionsRecorded++;
            } catch (InvalidArgumentException $e) {
                // Skip invalid actions but continue importing others
                error_log("Skipping invalid action during import: " . $e->getMessage());
            }
        }
    }

    /**
     * Get statistics about recorded actions
     * 
     * @return array<string, mixed> Statistics data
     */
    public function getStatistics(): array
    {
        $sessionCount = count($this->sessionActions);
        $actionCounts = array_map('count', $this->sessionActions);
        
        return [
            'totalSessions' => $sessionCount,
            'totalActions' => $this->totalActionsRecorded,
            'averageActionsPerSession' => $sessionCount > 0 ? round($this->totalActionsRecorded / $sessionCount, 2) : 0,
            'largestSession' => $sessionCount > 0 ? max($actionCounts) : 0,
            'smallestSession' => $sessionCount > 0 ? min($actionCounts) : 0,
            'activeSessions' => $this->getActiveSessions(),
        ];
    }

    /**
     * Initialize a new session
     */
    private function initializeSession(string $sessionId): void
    {
        $this->sessionActions[$sessionId] = [];
        $this->sessionMetadata[$sessionId] = new ActionMetadata(
            sessionId: $sessionId,
            startTime: time(),
            userAgent: '',
            viewport: null,
            url: ''
        );
    }

    /**
     * Validate action data against schema
     * 
     * @param string $type Action type
     * @param array<string, mixed> $data Action data
     * @throws InvalidArgumentException If validation fails
     */
    private function validateActionData(string $type, array $data): void
    {
        $requiredFields = self::ACTION_SCHEMAS[$type];

        foreach ($requiredFields as $field) {
            if (!array_key_exists($field, $data)) {
                throw new InvalidArgumentException("Missing required field '{$field}' for action type '{$type}'");
            }
        }
    }

    /**
     * Sanitize action data to prevent issues
     * 
     * @param array<string, mixed> $data Raw action data
     * @return array<string, mixed> Sanitized data
     */
    private function sanitizeActionData(array $data): array
    {
        $sanitized = [];

        foreach ($data as $key => $value) {
            if (!is_string($key)) {
                continue;
            }

            $sanitizedKey = preg_replace('/[^\w\-_.]/', '', $key);
            if ($sanitizedKey === '') {
                continue;
            }

            if (is_string($value)) {
                $sanitized[$sanitizedKey] = mb_substr($value, 0, 10000); // Limit string length
            } elseif (is_scalar($value) || is_array($value)) {
                $sanitized[$sanitizedKey] = $value;
            }
        }

        return $sanitized;
    }

    /**
     * Update session metadata based on action data
     */
    private function updateSessionMetadata(string $sessionId, ActionData $actionData): void
    {
        $metadata = $this->sessionMetadata[$sessionId];

        // Update last action time
        $metadata->lastActionTime = $actionData->timestamp;

        // Update URL if provided
        if (!empty($actionData->url)) {
            $metadata->url = $actionData->url;
        }

        // Update viewport if provided
        if ($actionData->viewport !== null) {
            $metadata->viewport = $actionData->viewport;
        }

        // Update user agent for session start actions
        if ($actionData->type === 'session:start' && isset($actionData->data['userAgent'])) {
            $metadata->userAgent = (string)$actionData->data['userAgent'];
        }
    }
}


/**
 * Represents metadata for a recording session
 */
final class ActionMetadata
{
    public function __construct(
        public string $sessionId,
        public int $startTime,
        public string $userAgent,
        public ?array $viewport,
        public string $url,
        public ?int $lastActionTime = null
    ) {
    }

    /**
     * Convert to array format for serialization
     * 
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'sessionId' => $this->sessionId,
            'startTime' => $this->startTime,
            'lastActionTime' => $this->lastActionTime,
            'userAgent' => $this->userAgent,
            'viewport' => $this->viewport,
            'url' => $this->url,
        ];
    }

    /**
     * Create from array format
     * 
     * @param array<string, mixed> $data Array data
     * @return self
     */
    public static function fromArray(array $data): self
    {
        return new self(
            sessionId: (string)($data['sessionId'] ?? ''),
            startTime: (int)($data['startTime'] ?? time()),
            userAgent: (string)($data['userAgent'] ?? ''),
            viewport: is_array($data['viewport']) ? $data['viewport'] : null,
            url: (string)($data['url'] ?? ''),
            lastActionTime: isset($data['lastActionTime']) ? (int)$data['lastActionTime'] : null
        );
    }
}
