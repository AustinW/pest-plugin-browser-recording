<?php

declare(strict_types=1);

namespace PestPluginBrowserRecording\Communication;

use InvalidArgumentException;
use JsonException;
use Pest\Browser\Playwright\Page;

/**
 * Handles communication between PHP and browser JavaScript for recording actions
 * 
 * This class provides a robust communication layer between the PHP recording session
 * and the browser-side JavaScript recorder, enabling real-time action recording.
 */
final class BrowserCommunicator
{
    /**
     * Maximum number of actions to poll at once
     */
    private const MAX_ACTIONS_PER_POLL = 50;

    /**
     * Timeout for JavaScript evaluation (in milliseconds)
     */
    private const JS_TIMEOUT = 5000;

    /**
     * @var callable|null
     */
    private $actionHandler;

    /**
     * @var array<string, mixed>
     */
    private array $stats = [
        'actionsProcessed' => 0,
        'errors' => 0,
        'lastPollTime' => null,
    ];

    /**
     * @param callable|null $actionHandler Callback to handle received actions
     */
    public function __construct(?callable $actionHandler = null)
    {
        $this->actionHandler = $actionHandler;
    }

    /**
     * Set the action handler callback
     */
    public function setActionHandler(callable $handler): void
    {
        $this->actionHandler = $handler;
    }

    /**
     * Initialize communication channels in the browser
     */
    public function initializeCommunication(Page $page, string $sessionId): void
    {
        try {
            $initScript = $this->buildInitializationScript($sessionId);
            $page->evaluate($initScript);
        } catch (\Exception $e) {
            throw new \RuntimeException(
                "Failed to initialize browser communication: " . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Poll for recorded actions from the browser
     * 
     * @return array<array<string, mixed>> Array of actions received
     */
    public function pollForActions(Page $page): array
    {
        $this->stats['lastPollTime'] = time();

        try {
            $actions = $this->retrieveActionsFromBrowser($page);
            $validActions = $this->validateAndProcessActions($actions);
            
            $this->stats['actionsProcessed'] += count($validActions);
            
            return $validActions;
        } catch (\Exception $e) {
            $this->stats['errors']++;
            throw new \RuntimeException(
                "Failed to poll for actions: " . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Send a message to the browser
     */
    public function sendMessageToBrowser(Page $page, string $type, array $data = []): void
    {
        try {
            $message = [
                'type' => $type,
                'data' => $data,
                'timestamp' => time(),
            ];

            $script = 'if (window.__pestRecordingMessages) { 
                window.__pestRecordingMessages.push(' . json_encode($message, JSON_THROW_ON_ERROR) . ');
            }';

            $page->evaluate($script);
        } catch (JsonException $e) {
            throw new InvalidArgumentException(
                "Failed to serialize message data: " . $e->getMessage(),
                0,
                $e
            );
        } catch (\Exception $e) {
            throw new \RuntimeException(
                "Failed to send message to browser: " . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Check if the recording session is still active in the browser
     */
    public function isSessionActive(Page $page): bool
    {
        try {
            $result = $page->evaluate('window.__pestRecordingSession?.isActive || false');
            return is_bool($result) ? $result : false;
        } catch (\Exception) {
            return false;
        }
    }

    /**
     * Stop communication and clean up browser resources
     */
    public function stopCommunication(Page $page): void
    {
        try {
            $cleanupScript = '
                if (window.__pestRecorder) {
                    window.__pestRecorder.stop();
                }
                if (window.__pestRecordingSession) {
                    window.__pestRecordingSession.isActive = false;
                }
                // Clear any remaining actions
                window.__pestRecordingActions = [];
                window.__pestRecordingMessages = [];
            ';
            
            $page->evaluate($cleanupScript);
        } catch (\Exception $e) {
            // Log but don't throw - cleanup should be non-fatal
            error_log("Warning: Failed to clean up browser communication: " . $e->getMessage());
        }
    }

    /**
     * Get communication statistics
     * 
     * @return array<string, mixed>
     */
    public function getStats(): array
    {
        return $this->stats;
    }

    /**
     * Reset communication statistics
     */
    public function resetStats(): void
    {
        $this->stats = [
            'actionsProcessed' => 0,
            'errors' => 0,
            'lastPollTime' => null,
        ];
    }

    /**
     * Build the JavaScript initialization script
     */
    private function buildInitializationScript(string $sessionId): string
    {
        return '
            // Initialize communication arrays
            window.__pestRecordingActions = window.__pestRecordingActions || [];
            window.__pestRecordingMessages = window.__pestRecordingMessages || [];
            
            // Set up session tracking
            window.__pestRecordingSession = {
                isActive: true,
                sessionId: "' . addslashes($sessionId) . '",
                startTime: Date.now(),
                version: "1.0.0"
            };
            
            // Add error handling for communication
            window.__pestCommunicationError = function(error) {
                console.error("[PestRecorder] Communication error:", error);
                if (window.__pestRecordingActions) {
                    window.__pestRecordingActions.push({
                        type: "communication:error",
                        data: { error: error.toString() },
                        timestamp: Date.now(),
                        sessionId: window.__pestRecordingSession?.sessionId
                    });
                }
            };
            
            // Add heartbeat mechanism
            window.__pestHeartbeat = function() {
                if (window.__pestRecordingSession?.isActive && window.__pestRecordingActions) {
                    window.__pestRecordingActions.push({
                        type: "session:heartbeat",
                        data: { timestamp: Date.now() },
                        timestamp: Date.now(),
                        sessionId: window.__pestRecordingSession?.sessionId
                    });
                }
            };
            
            // Set up periodic heartbeat (every 30 seconds)
            if (window.__pestRecordingSession?.isActive) {
                setInterval(window.__pestHeartbeat, 30000);
            }
        ';
    }

    /**
     * Retrieve actions from the browser and clear the queue
     */
    private function retrieveActionsFromBrowser(Page $page): mixed
    {
        $script = '
            if (window.__pestRecordingActions) {
                const actions = [...window.__pestRecordingActions].slice(0, ' . self::MAX_ACTIONS_PER_POLL . ');
                window.__pestRecordingActions = window.__pestRecordingActions.slice(' . self::MAX_ACTIONS_PER_POLL . ');
                return actions;
            }
            return [];
        ';

        return $page->evaluate($script);
    }

    /**
     * Validate and process actions received from the browser
     * 
     * @param mixed $actions Raw actions from browser
     * @return array<array<string, mixed>> Validated actions
     */
    private function validateAndProcessActions(mixed $actions): array
    {
        if (!is_array($actions)) {
            return [];
        }

        $validActions = [];

        foreach ($actions as $action) {
            if (!is_array($action)) {
                continue;
            }

            // Validate required fields
            if (!isset($action['type']) || !is_string($action['type'])) {
                continue;
            }

            if (!isset($action['data']) || !is_array($action['data'])) {
                continue;
            }

            // Sanitize and validate the action
            $validatedAction = [
                'type' => $this->sanitizeString($action['type']),
                'data' => $this->sanitizeActionData($action['data']),
                'timestamp' => isset($action['timestamp']) && is_numeric($action['timestamp']) 
                    ? (int)$action['timestamp'] 
                    : time(),
                'sessionId' => isset($action['sessionId']) && is_string($action['sessionId'])
                    ? $this->sanitizeString($action['sessionId'])
                    : '',
                'url' => isset($action['url']) && is_string($action['url'])
                    ? $this->sanitizeString($action['url'])
                    : '',
            ];

            $validActions[] = $validatedAction;

            // Call the action handler if set
            if ($this->actionHandler) {
                try {
                    call_user_func($this->actionHandler, $validatedAction['type'], $validatedAction['data']);
                } catch (\Exception $e) {
                    error_log("Action handler error: " . $e->getMessage());
                }
            }
        }

        return $validActions;
    }

    /**
     * Sanitize action data to prevent injection attacks
     * 
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function sanitizeActionData(array $data): array
    {
        $sanitized = [];

        foreach ($data as $key => $value) {
            if (!is_string($key)) {
                continue;
            }

            $sanitizedKey = $this->sanitizeString($key);

            if (is_string($value)) {
                $sanitized[$sanitizedKey] = $this->sanitizeString($value);
            } elseif (is_numeric($value)) {
                $sanitized[$sanitizedKey] = $value;
            } elseif (is_bool($value)) {
                $sanitized[$sanitizedKey] = $value;
            } elseif (is_array($value)) {
                $sanitized[$sanitizedKey] = $this->sanitizeActionData($value);
            } else {
                $sanitized[$sanitizedKey] = null;
            }
        }

        return $sanitized;
    }

    /**
     * Sanitize string values
     */
    private function sanitizeString(string $value): string
    {
        // Remove null bytes and control characters except for common whitespace
        $sanitized = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value);
        
        // Limit length to prevent memory issues
        return mb_substr($sanitized ?: '', 0, 10000);
    }
}
