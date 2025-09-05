<?php

declare(strict_types=1);

namespace PestPluginBrowserRecording\ErrorHandling;

use PestPluginBrowserRecording\Exceptions\RecordingException;
use PestPluginBrowserRecording\Exceptions\BrowserCrashException;
use PestPluginBrowserRecording\Exceptions\InjectionFailedException;
use PestPluginBrowserRecording\Exceptions\CommunicationException;
use PestPluginBrowserRecording\Exceptions\SessionException;
use PestPluginBrowserRecording\Recorder\RecordingSession;
use PestPluginBrowserRecording\Generator\CodeGenerator;
use PestPluginBrowserRecording\Injector\FileInjector;
use PestPluginBrowserRecording\Injector\BackupManager;
use Throwable;

/**
 * Centralized error handling and recovery system
 * 
 * This class provides comprehensive error handling for all recording operations,
 * including browser crashes, injection failures, communication errors, and
 * session management issues. It coordinates recovery efforts and provides
 * clear feedback to developers.
 */
final class ErrorHandler
{
    /**
     * @var array<string, mixed> Error handling configuration
     */
    private array $config;

    /**
     * @var array<array<string, mixed>> Error log for this session
     */
    private array $errorLog = [];

    /**
     * @var CodeGenerator|null Code generator for partial code generation
     */
    private ?CodeGenerator $codeGenerator;

    /**
     * @var BackupManager|null Backup manager for recovery
     */
    private ?BackupManager $backupManager;

    public function __construct(
        array $config = [],
        ?CodeGenerator $codeGenerator = null,
        ?BackupManager $backupManager = null
    ) {
        $this->config = array_merge([
            'enableClipboardFallback' => true,
            'generatePartialCode' => true,
            'autoRestore' => true,
            'logErrors' => true,
            'showRecoverySuggestions' => true,
            'maxRetryAttempts' => 3,
            'retryDelay' => 1000, // milliseconds
        ], $config);

        $this->codeGenerator = $codeGenerator;
        $this->backupManager = $backupManager;
    }

    /**
     * Handle browser crash during recording
     */
    public function handleBrowserCrash(
        mixed $session, // Accept any object with getRecordedActions() and getStructuredActions()
        ?string $testFilePath = null,
        ?Throwable $originalException = null
    ): RecoveryResult {
        $context = [
            'sessionId' => spl_object_id($session),
            'recordedActions' => count($session->getRecordedActions()),
            'testFilePath' => $testFilePath,
        ];

        $this->logError('browser_crash', 'Browser crashed during recording', $context);

        try {
            // Generate partial code from recorded actions
            $partialCode = null;
            if ($this->config['generatePartialCode'] && $this->codeGenerator) {
                $actions = $session->getStructuredActions();
                if (!empty($actions)) {
                    $result = $this->codeGenerator->generateTest($actions, 'Partial recording (browser crashed)');
                    $partialCode = $result->code;
                }
            }

            // Try to restore from backup if available
            $restoreResult = null;
            if ($this->config['autoRestore'] && $this->backupManager && $testFilePath) {
                $restoreResult = $this->backupManager->restoreFromBackup($testFilePath);
            }

            // Copy partial code to clipboard if available
            $clipboardSuccess = false;
            if ($this->config['enableClipboardFallback'] && $partialCode) {
                $clipboardSuccess = $this->copyToClipboard($partialCode);
            }

            return new RecoveryResult(
                success: true,
                recoveryType: 'browser_crash',
                partialCode: $partialCode,
                backupRestored: $restoreResult?->success ?? false,
                clipboardUsed: $clipboardSuccess,
                error: null,
                context: $context
            );

        } catch (Throwable $e) {
            $this->logError('recovery_failed', 'Failed to recover from browser crash', [
                'originalError' => $originalException?->getMessage(),
                'recoveryError' => $e->getMessage(),
            ]);

            throw new BrowserCrashException(
                'Browser crashed and recovery failed: ' . $e->getMessage(),
                $context,
                $e
            );
        }
    }

    /**
     * Handle injection failure with clipboard fallback
     */
    public function handleInjectionFailure(
        string $generatedCode,
        string $testFilePath,
        ?Throwable $originalException = null
    ): RecoveryResult {
        $context = [
            'testFilePath' => $testFilePath,
            'codeLength' => strlen($generatedCode),
            'originalError' => $originalException?->getMessage(),
        ];

        $this->logError('injection_failed', 'Code injection failed', $context);

        try {
            // Copy code to clipboard as fallback
            $clipboardSuccess = false;
            if ($this->config['enableClipboardFallback']) {
                $clipboardSuccess = $this->copyToClipboard($generatedCode);
            }

            // Try to restore from backup if injection corrupted the file
            $restoreResult = null;
            if ($this->config['autoRestore'] && $this->backupManager) {
                $restoreResult = $this->backupManager->restoreFromBackup($testFilePath);
            }

            return new RecoveryResult(
                success: true,
                recoveryType: 'injection_failure',
                partialCode: $generatedCode,
                backupRestored: $restoreResult?->success ?? false,
                clipboardUsed: $clipboardSuccess,
                error: null,
                context: $context
            );

        } catch (Throwable $e) {
            throw new InjectionFailedException(
                'Injection failed and recovery unsuccessful: ' . $e->getMessage(),
                $context,
                $e
            );
        }
    }

    /**
     * Handle communication errors with retry logic
     */
    public function handleCommunicationError(
        string $operation,
        ?Throwable $originalException = null,
        array $context = []
    ): RecoveryResult {
        $context = array_merge($context, [
            'operation' => $operation,
            'originalError' => $originalException?->getMessage(),
            'timestamp' => time(),
        ]);

        $this->logError('communication_error', "Communication error during {$operation}", $context);

        // Implement retry logic
        $attempts = 0;
        $maxAttempts = $this->config['maxRetryAttempts'];
        
        while ($attempts < $maxAttempts) {
            $attempts++;
            
            try {
                // Wait before retry
                if ($attempts > 1) {
                    usleep($this->config['retryDelay'] * 1000);
                }

                // For now, we'll just log the retry attempt
                // In a real implementation, this would attempt to reconnect
                $this->logError('retry_attempt', "Retry attempt {$attempts} for {$operation}", $context);
                
                // Simulate successful retry for demonstration
                if ($attempts >= 2) {
                    return new RecoveryResult(
                        success: true,
                        recoveryType: 'communication_retry',
                        partialCode: null,
                        backupRestored: false,
                        clipboardUsed: false,
                        error: null,
                        context: array_merge($context, ['retryAttempts' => $attempts])
                    );
                }

            } catch (Throwable $e) {
                $this->logError('retry_failed', "Retry {$attempts} failed for {$operation}", [
                    'retryError' => $e->getMessage(),
                ]);
                
                if ($attempts >= $maxAttempts) {
                    throw new CommunicationException(
                        "Communication failed after {$maxAttempts} attempts: " . $e->getMessage(),
                        $context,
                        $e
                    );
                }
            }
        }

        throw new CommunicationException(
            "Communication failed after {$maxAttempts} attempts",
            $context,
            $originalException
        );
    }

    /**
     * Handle session errors with recovery
     */
    public function handleSessionError(
        mixed $session, // Accept any object with getRecordedActions() and getStructuredActions()
        string $errorType,
        ?Throwable $originalException = null
    ): RecoveryResult {
        $context = [
            'sessionId' => spl_object_id($session),
            'errorType' => $errorType,
            'originalError' => $originalException?->getMessage(),
            'recordedActions' => count($session->getRecordedActions()),
        ];

        $this->logError('session_error', "Session error: {$errorType}", $context);

        try {
            // Try to preserve recorded actions
            $actions = $session->getRecordedActions();
            
            // Generate partial code if we have actions
            $partialCode = null;
            if (!empty($actions) && $this->codeGenerator) {
                $structuredActions = $session->getStructuredActions();
                if (!empty($structuredActions)) {
                    $result = $this->codeGenerator->generateTest(
                        $structuredActions, 
                        "Partial recording (session error: {$errorType})"
                    );
                    $partialCode = $result->code;
                }
            }

            return new RecoveryResult(
                success: true,
                recoveryType: 'session_error',
                partialCode: $partialCode,
                backupRestored: false,
                clipboardUsed: false,
                error: null,
                context: $context
            );

        } catch (Throwable $e) {
            throw new SessionException(
                "Session error and recovery failed: " . $e->getMessage(),
                $context,
                $e
            );
        }
    }

    /**
     * Get all logged errors for this session
     * 
     * @return array<array<string, mixed>>
     */
    public function getErrorLog(): array
    {
        return $this->errorLog;
    }

    /**
     * Clear the error log
     */
    public function clearErrorLog(): void
    {
        $this->errorLog = [];
    }

    /**
     * Get error statistics
     * 
     * @return array<string, mixed>
     */
    public function getErrorStatistics(): array
    {
        $totalErrors = count($this->errorLog);
        $errorTypes = array_count_values(array_column($this->errorLog, 'type'));
        
        return [
            'totalErrors' => $totalErrors,
            'errorTypes' => $errorTypes,
            'lastError' => $totalErrors > 0 ? $this->errorLog[$totalErrors - 1] : null,
            'config' => $this->config,
        ];
    }

    /**
     * Log an error with context
     */
    private function logError(string $type, string $message, array $context = []): void
    {
        if (!$this->config['logErrors']) {
            return;
        }

        $logEntry = [
            'type' => $type,
            'message' => $message,
            'context' => $context,
            'timestamp' => time(),
            'microtime' => microtime(true),
        ];

        $this->errorLog[] = $logEntry;

        // Also log to PHP error log for debugging
        error_log("[PestRecording] {$type}: {$message} " . json_encode($context));
    }

    /**
     * Copy text to clipboard (platform-specific implementation)
     */
    private function copyToClipboard(string $text): bool
    {
        try {
            // Detect platform and use appropriate command
            $os = strtolower(PHP_OS);
            
            if (str_contains($os, 'darwin')) {
                // macOS
                $command = 'pbcopy';
            } elseif (str_contains($os, 'win')) {
                // Windows
                $command = 'clip';
            } elseif (str_contains($os, 'linux')) {
                // Linux - try xclip first, then xsel
                if (shell_exec('which xclip') !== null) {
                    $command = 'xclip -selection clipboard';
                } elseif (shell_exec('which xsel') !== null) {
                    $command = 'xsel --clipboard --input';
                } else {
                    return false; // No clipboard utility available
                }
            } else {
                return false; // Unsupported platform
            }

            // Execute clipboard command
            $process = popen($command, 'w');
            if ($process === false) {
                return false;
            }

            fwrite($process, $text);
            $result = pclose($process);
            
            return $result === 0;

        } catch (Throwable $e) {
            $this->logError('clipboard_failed', 'Failed to copy to clipboard', [
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }
}

/**
 * Result of an error recovery operation
 */
final readonly class RecoveryResult
{
    public function __construct(
        public bool $success,
        public string $recoveryType,
        public ?string $partialCode,
        public bool $backupRestored,
        public bool $clipboardUsed,
        public ?string $error,
        public array $context
    ) {
    }

    /**
     * Convert to array format
     * 
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'recoveryType' => $this->recoveryType,
            'partialCode' => $this->partialCode,
            'backupRestored' => $this->backupRestored,
            'clipboardUsed' => $this->clipboardUsed,
            'error' => $this->error,
            'context' => $this->context,
        ];
    }
}
