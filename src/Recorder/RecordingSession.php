<?php

declare(strict_types=1);

namespace PestPluginBrowserRecording\Recorder;

use PestPluginBrowserRecording\Config\RecordingConfig;
use PestPluginBrowserRecording\Communication\BrowserCommunicator;
use PestPluginBrowserRecording\Recorder\ActionRecorder;

/**
 * Manages the lifecycle of a browser recording session
 * 
 * This class handles the initialization, management, and cleanup of browser
 * recording sessions, coordinating between the browser page instance and
 * the recording infrastructure.
 */
final class RecordingSession
{
    /**
     * @var array<int, array<string, mixed>> Recorded actions from the browser
     */
    private array $recordedActions = [];

    /**
     * @var mixed The browser page instance (typically Webpage or AwaitableWebpage)
     */
    private mixed $pageInstance;

    /**
     * @var array<string, mixed> Configuration options for this recording session
     */
    private array $config;

    /**
     * @var BrowserCommunicator Communication handler for browser-PHP interaction
     */
    private BrowserCommunicator $communicator;

    /**
     * @var ActionRecorder Structured action storage handler
     */
    private ActionRecorder $actionRecorder;

    /**
     * Create a new recording session
     *
     * @param mixed $pageInstance The browser page instance
     * @param array<string, mixed> $config Recording configuration
     */
    public function __construct(mixed $pageInstance, array $config = [])
    {
        $this->pageInstance = $pageInstance;
        $this->config = $config;
        
        // Validate configuration
        RecordingConfig::validate($config);
        
        // Initialize communication handler
        $this->communicator = new BrowserCommunicator([$this, 'handleAction']);
        
        // Initialize action recorder
        $this->actionRecorder = new ActionRecorder();
    }

    /**
     * Start the recording session
     * 
     * Initializes the browser recording infrastructure, injects the JavaScript
     * recorder, and sets up communication channels.
     */
    public function start(): void
    {
        $this->recordedActions = [];
        
        // Try to access the underlying Playwright page for JavaScript injection
        $playwrightPage = $this->getPlaywrightPage();
        
        if ($playwrightPage === null) {
            // If we can't access the page yet, defer initialization
            // This might happen if the page hasn't been fully created yet
            return;
        }
        
        // Inject the recording JavaScript
        $this->injectRecorderScript($playwrightPage);
        
        // Set up communication channel
        $this->communicator->initializeCommunication($playwrightPage, spl_object_id($this));
        
        // Start the recorder
        $this->startRecorderInBrowser($playwrightPage);
    }

    /**
     * Get the underlying Playwright page instance from the page object
     */
    private function getPlaywrightPage(): ?\Pest\Browser\Playwright\Page
    {
        // Handle different types of page instances
        if (method_exists($this->pageInstance, 'page')) {
            return $this->pageInstance->page();
        }
        
        // If it's already a Playwright Page instance
        if ($this->pageInstance instanceof \Pest\Browser\Playwright\Page) {
            return $this->pageInstance;
        }
        
        // Try to access via reflection if it's a Webpage with private $page property
        if (property_exists($this->pageInstance, 'page')) {
            $reflection = new \ReflectionProperty($this->pageInstance, 'page');
            if ($reflection->isPrivate()) {
                $reflection->setAccessible(true);
                return $reflection->getValue($this->pageInstance);
            }
        }
        
        return null;
    }

    /**
     * Inject the recorder JavaScript into the page
     */
    private function injectRecorderScript(\Pest\Browser\Playwright\Page $page): void
    {
        $recorderScript = file_get_contents(__DIR__ . '/../../resources/js/recorder.js');
        
        // Inject the recorder class and initialize it
        $initScript = $recorderScript . "\n" . 
            "window.__pestRecorder = new PestRecorder(" . json_encode($this->config) . ");";
        
        $page->evaluate($initScript);
    }

    /**
     * Start the recorder in the browser
     */
    private function startRecorderInBrowser(\Pest\Browser\Playwright\Page $page): void
    {
        $page->evaluate('
            if (window.__pestRecorder) {
                window.__pestRecorder.start();
            }
        ');
    }

    /**
     * Poll for recorded actions from the browser
     * 
     * @return array<array<string, mixed>> Array of actions received
     */
    public function pollForActions(\Pest\Browser\Playwright\Page $page): array
    {
        return $this->communicator->pollForActions($page);
    }

    /**
     * Check if the recording session is still active
     */
    public function isActive(\Pest\Browser\Playwright\Page $page): bool
    {
        return $this->communicator->isSessionActive($page);
    }

    /**
     * Stop the recording session
     */
    public function stop(\Pest\Browser\Playwright\Page $page): void
    {
        // Collect any remaining actions
        $this->pollForActions($page);
        
        // Stop communication and clean up
        $this->communicator->stopCommunication($page);
        
        // Finish recording
        $this->finishRecording();
    }

    /**
     * Handle an action recorded from the browser
     *
     * @param string $type The type of action (click, fill, etc.)
     * @param array<string, mixed> $data Action data (selector, value, etc.)
     */
    public function handleAction(string $type, array $data): void
    {
        $sessionId = (string)spl_object_id($this);
        
        // Extract context information
        $context = [
            'timestamp' => $data['timestamp'] ?? time(),
            'url' => $data['url'] ?? '',
            'viewport' => $data['viewport'] ?? null,
            'metadata' => $data['metadata'] ?? [],
        ];

        // Remove context data from the action data to avoid duplication
        $cleanData = $data;
        unset($cleanData['timestamp'], $cleanData['url'], $cleanData['viewport'], $cleanData['metadata']);

        // Always maintain the legacy format for backward compatibility
        $legacyAction = [
            'type' => $type,
            'data' => $data,
            'timestamp' => $context['timestamp'],
            'sessionId' => $sessionId,
        ];
        $this->recordedActions[] = $legacyAction;
        
        try {
            // Try to record the action using ActionRecorder for structured storage
            // This provides enhanced features but isn't required for basic functionality
            $this->actionRecorder->recordAction($sessionId, $type, $cleanData, $context);
        } catch (\InvalidArgumentException $e) {
            // Log the error but don't fail the entire session
            // The action is still recorded in the legacy format above
            error_log("Failed to record structured action '{$type}': " . $e->getMessage());
        }
    }

    /**
     * Get the current configuration
     *
     * @return array<string, mixed>
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    /**
     * Get the recorded actions
     *
     * @return array<int, array<string, mixed>>
     */
    public function getRecordedActions(): array
    {
        return $this->recordedActions;
    }

    /**
     * Get communication statistics
     * 
     * @return array<string, mixed>
     */
    public function getCommunicationStats(): array
    {
        return $this->communicator->getStats();
    }

    /**
     * Get the action recorder instance
     * 
     * @return ActionRecorder
     */
    public function getActionRecorder(): ActionRecorder
    {
        return $this->actionRecorder;
    }

    /**
     * Get structured actions for this session
     * 
     * @return array<ActionData>
     */
    public function getStructuredActions(): array
    {
        $sessionId = (string)spl_object_id($this);
        return $this->actionRecorder->getSessionActions($sessionId);
    }

    /**
     * Export session data in structured format
     * 
     * @return array<string, mixed>
     */
    public function exportStructuredSession(): array
    {
        $sessionId = (string)spl_object_id($this);
        return $this->actionRecorder->exportSession($sessionId);
    }

    /**
     * Preserve browser context state (cookies, localStorage, sessionStorage)
     */
    public function preserveState(\Pest\Browser\Playwright\Page $page): array
    {
        return [
            'cookies' => $page->evaluate('document.cookie'),
            'localStorage' => $page->evaluate('JSON.stringify(localStorage)'),
            'sessionStorage' => $page->evaluate('JSON.stringify(sessionStorage)'),
            'url' => $page->evaluate('window.location.href'),
        ];
    }

    /**
     * Restore browser context state
     */
    public function restoreState(\Pest\Browser\Playwright\Page $page, array $state): void
    {
        // Restore localStorage
        if (isset($state['localStorage'])) {
            $page->evaluate('
                const data = ' . $state['localStorage'] . ';
                for (const [key, value] of Object.entries(data)) {
                    localStorage.setItem(key, value);
                }
            ');
        }

        // Restore sessionStorage
        if (isset($state['sessionStorage'])) {
            $page->evaluate('
                const data = ' . $state['sessionStorage'] . ';
                for (const [key, value] of Object.entries(data)) {
                    sessionStorage.setItem(key, value);
                }
            ');
        }

        // Note: Cookies would need to be restored via Playwright's context API
        // which is not directly accessible through pest-plugin-browser
    }

    /**
     * Finish the recording session and generate code
     * 
     * Processes all recorded actions, generates Pest test code,
     * and injects it into the test file.
     */
    public function finishRecording(): void
    {
        // TODO: Implement code generation and injection
        // This will be implemented in Tasks 9 and 10
    }
}
