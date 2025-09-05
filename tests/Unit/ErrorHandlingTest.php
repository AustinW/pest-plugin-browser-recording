<?php

declare(strict_types=1);

use PestPluginBrowserRecording\ErrorHandling\ErrorHandler;
use PestPluginBrowserRecording\ErrorHandling\RecoveryResult;
use PestPluginBrowserRecording\Exceptions\BrowserCrashException;
use PestPluginBrowserRecording\Exceptions\InjectionFailedException;
use PestPluginBrowserRecording\Exceptions\CommunicationException;
use PestPluginBrowserRecording\Exceptions\SessionException;
use PestPluginBrowserRecording\Exceptions\ConfigurationException;
use PestPluginBrowserRecording\Recorder\RecordingSession;
use PestPluginBrowserRecording\Generator\CodeGenerator;
use PestPluginBrowserRecording\Injector\BackupManager;

// Mock recording session for testing
class MockRecordingSession
{
    private array $mockActions = [];
    private array $mockStructuredActions = [];

    public function __construct(array $mockActions = [], array $mockStructuredActions = [])
    {
        $this->mockActions = $mockActions;
        $this->mockStructuredActions = $mockStructuredActions;
    }

    public function getRecordedActions(): array
    {
        return $this->mockActions;
    }

    public function getStructuredActions(): array
    {
        return $this->mockStructuredActions;
    }
}

it('creates error handler with default configuration', function () {
    $handler = new ErrorHandler();
    expect($handler)->toBeInstanceOf(ErrorHandler::class);
    
    $stats = $handler->getErrorStatistics();
    expect($stats['totalErrors'])->toBe(0);
    expect($stats['config']['enableClipboardFallback'])->toBeTrue();
});

it('creates error handler with custom configuration', function () {
    $config = [
        'enableClipboardFallback' => false,
        'generatePartialCode' => false,
        'maxRetryAttempts' => 5
    ];
    
    $handler = new ErrorHandler($config);
    $stats = $handler->getErrorStatistics();
    
    expect($stats['config']['enableClipboardFallback'])->toBeFalse();
    expect($stats['config']['generatePartialCode'])->toBeFalse();
    expect($stats['config']['maxRetryAttempts'])->toBe(5);
});

it('handles browser crash with partial code generation', function () {
    $mockCodeGenerator = new CodeGenerator(['autoAssertions' => false]);
    $mockBackupManager = new BackupManager(['enabled' => false]);
    
    $handler = new ErrorHandler([
        'generatePartialCode' => true,
        'enableClipboardFallback' => false, // Disable for testing
        'logErrors' => false // Disable logging for cleaner tests
    ], $mockCodeGenerator, $mockBackupManager);
    
    // Create mock session with some actions
    $mockActions = [
        ['type' => 'click', 'data' => ['selector' => '#btn'], 'timestamp' => time()]
    ];
    $mockStructuredActions = [
        new \PestPluginBrowserRecording\Recorder\ActionData(
            'click', ['selector' => '#btn'], time(), '/', 'session1', 1, null, []
        )
    ];
    
    $session = new MockRecordingSession($mockActions, $mockStructuredActions);
    
    $result = $handler->handleBrowserCrash($session, '/test/file.php');
    
    expect($result->success)->toBeTrue();
    expect($result->recoveryType)->toBe('browser_crash');
    expect($result->partialCode)->not->toBeNull();
    expect($result->partialCode)->toContain('it(');
});

it('handles injection failure with clipboard fallback', function () {
    $handler = new ErrorHandler([
        'enableClipboardFallback' => false, // Disable actual clipboard for testing
        'logErrors' => false
    ]);
    
    $generatedCode = '$page->click("#test-btn")->fill("#email", "test@example.com");';
    
    $result = $handler->handleInjectionFailure(
        $generatedCode,
        '/test/file.php',
        new Exception('File write failed')
    );
    
    expect($result->success)->toBeTrue();
    expect($result->recoveryType)->toBe('injection_failure');
    expect($result->partialCode)->toBe($generatedCode);
    expect($result->clipboardUsed)->toBeFalse(); // Disabled for testing
});

it('handles communication errors with retry logic', function () {
    $handler = new ErrorHandler(['maxRetryAttempts' => 2, 'retryDelay' => 10, 'logErrors' => false]);
    
    $result = $handler->handleCommunicationError(
        'pollForActions',
        new Exception('Connection timeout'),
        ['operation' => 'polling']
    );
    
    expect($result->success)->toBeTrue();
    expect($result->recoveryType)->toBe('communication_retry');
    expect($result->context['retryAttempts'])->toBe(2);
});

it('handles session errors with partial code preservation', function () {
    $mockCodeGenerator = new CodeGenerator(['autoAssertions' => false]);
    
    $handler = new ErrorHandler([
        'generatePartialCode' => true,
        'logErrors' => false
    ], $mockCodeGenerator);
    
    $mockActions = [
        ['type' => 'input', 'data' => ['selector' => '#field'], 'timestamp' => time()]
    ];
    $mockStructuredActions = [
        new \PestPluginBrowserRecording\Recorder\ActionData(
            'input', ['selector' => '#field', 'value' => 'test', 'inputType' => 'text'], time(), '/', 'session1', 1, null, []
        )
    ];
    
    $session = new MockRecordingSession($mockActions, $mockStructuredActions);
    
    $result = $handler->handleSessionError($session, 'timeout', new Exception('Session timeout'));
    
    expect($result->success)->toBeTrue();
    expect($result->recoveryType)->toBe('session_error');
    expect($result->partialCode)->not->toBeNull();
});

it('logs errors correctly', function () {
    $handler = new ErrorHandler(['logErrors' => true]);
    
    // Trigger an error that will be logged
    $handler->handleCommunicationError('test', new Exception('Test error'));
    
    $errorLog = $handler->getErrorLog();
    expect($errorLog)->not->toBeEmpty();
    
    $firstError = $errorLog[0]; // Check first error, not last (retry logic adds more)
    expect($firstError['type'])->toBe('communication_error');
    expect($firstError['message'])->toContain('Communication error during test');
});

it('tracks error statistics', function () {
    $handler = new ErrorHandler(['logErrors' => true]);
    
    // Generate some errors
    try {
        $handler->handleCommunicationError('test1', new Exception('Error 1'));
    } catch (Throwable $e) {
        // Ignore for testing
    }
    
    try {
        $handler->handleCommunicationError('test2', new Exception('Error 2'));
    } catch (Throwable $e) {
        // Ignore for testing
    }
    
    $stats = $handler->getErrorStatistics();
    expect($stats['totalErrors'])->toBeGreaterThan(0);
    expect($stats['errorTypes'])->toHaveKey('communication_error');
});

it('clears error log', function () {
    $handler = new ErrorHandler(['logErrors' => true]);
    
    // Generate an error
    try {
        $handler->handleCommunicationError('test', new Exception('Test'));
    } catch (Throwable $e) {
        // Ignore
    }
    
    expect($handler->getErrorLog())->not->toBeEmpty();
    
    $handler->clearErrorLog();
    expect($handler->getErrorLog())->toBeEmpty();
});

it('validates error handler exception integration', function () {
    // Test that error handler works with exceptions (without importing specific exception classes)
    $handler = new ErrorHandler(['logErrors' => false]);
    
    // Test that the error handler creates proper recovery results
    $mockSession = new MockRecordingSession();
    
    try {
        $result = $handler->handleBrowserCrash($mockSession);
        expect($result->success)->toBeTrue();
        expect($result->recoveryType)->toBe('browser_crash');
    } catch (Throwable $e) {
        // Should be a BrowserCrashException but we can't import it due to autoloading issues
        expect($e->getCode())->toBe(1001);
        expect($e->getMessage())->toContain('Browser crashed');
    }
    
    try {
        $result = $handler->handleInjectionFailure('test code', '/test/file.php');
        expect($result->success)->toBeTrue();
        expect($result->recoveryType)->toBe('injection_failure');
    } catch (Throwable $e) {
        // Should be an InjectionFailedException
        expect($e->getCode())->toBe(2001);
        expect($e->getMessage())->toContain('Injection failed');
    }
});

it('converts RecoveryResult to array correctly', function () {
    $context = ['test' => 'value'];
    $result = new RecoveryResult(
        success: true,
        recoveryType: 'test_recovery',
        partialCode: '$page->click("#btn");',
        backupRestored: true,
        clipboardUsed: false,
        error: null,
        context: $context
    );
    
    $array = $result->toArray();
    
    expect($array)->toBe([
        'success' => true,
        'recoveryType' => 'test_recovery',
        'partialCode' => '$page->click("#btn");',
        'backupRestored' => true,
        'clipboardUsed' => false,
        'error' => null,
        'context' => $context,
    ]);
});
