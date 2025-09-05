<?php

declare(strict_types=1);

namespace PestPluginBrowserRecording\Exceptions;

use Exception;
use Throwable;

/**
 * Base exception for all recording-related errors
 */
abstract class RecordingException extends Exception
{
    /**
     * @var array<string, mixed> Additional context data
     */
    protected array $context;

    /**
     * @var string|null Recovery suggestion for the user
     */
    protected ?string $recoverySuggestion;

    public function __construct(
        string $message = '',
        int $code = 0,
        ?Throwable $previous = null,
        array $context = [],
        ?string $recoverySuggestion = null
    ) {
        parent::__construct($message, $code, $previous);
        $this->context = $context;
        $this->recoverySuggestion = $recoverySuggestion;
    }

    /**
     * Get additional context data
     * 
     * @return array<string, mixed>
     */
    public function getContext(): array
    {
        return $this->context;
    }

    /**
     * Get recovery suggestion
     */
    public function getRecoverySuggestion(): ?string
    {
        return $this->recoverySuggestion;
    }

    /**
     * Get formatted error details
     */
    public function getFormattedDetails(): array
    {
        return [
            'type' => static::class,
            'message' => $this->getMessage(),
            'code' => $this->getCode(),
            'file' => $this->getFile(),
            'line' => $this->getLine(),
            'context' => $this->getContext(),
            'recoverySuggestion' => $this->getRecoverySuggestion(),
            'timestamp' => time(),
        ];
    }
}

/**
 * Exception thrown when browser crashes or becomes unresponsive
 */
final class BrowserCrashException extends RecordingException
{
    public function __construct(
        string $message = 'Browser crashed or became unresponsive',
        array $context = [],
        ?Throwable $previous = null
    ) {
        $recoverySuggestion = 'Try restarting the browser or reducing the recording timeout. Check browser console for errors.';
        
        parent::__construct(
            $message,
            1001,
            $previous,
            $context,
            $recoverySuggestion
        );
    }
}

/**
 * Exception thrown when code injection fails
 */
final class InjectionFailedException extends RecordingException
{
    public function __construct(
        string $message = 'Failed to inject generated code into test file',
        array $context = [],
        ?Throwable $previous = null
    ) {
        $recoverySuggestion = 'Check file permissions and ensure the test file contains a ->record() call. The generated code has been copied to the clipboard.';
        
        parent::__construct(
            $message,
            2001,
            $previous,
            $context,
            $recoverySuggestion
        );
    }
}

/**
 * Exception thrown when communication with browser fails
 */
final class CommunicationException extends RecordingException
{
    public function __construct(
        string $message = 'Communication with browser failed',
        array $context = [],
        ?Throwable $previous = null
    ) {
        $recoverySuggestion = 'Check browser connection and try refreshing the page. Ensure JavaScript is enabled.';
        
        parent::__construct(
            $message,
            3001,
            $previous,
            $context,
            $recoverySuggestion
        );
    }
}

/**
 * Exception thrown when session management fails
 */
final class SessionException extends RecordingException
{
    public function __construct(
        string $message = 'Recording session error',
        array $context = [],
        ?Throwable $previous = null
    ) {
        $recoverySuggestion = 'Try stopping and restarting the recording session. Check browser developer tools for errors.';
        
        parent::__construct(
            $message,
            4001,
            $previous,
            $context,
            $recoverySuggestion
        );
    }
}

/**
 * Exception thrown when configuration is invalid
 */
final class ConfigurationException extends RecordingException
{
    public function __construct(
        string $message = 'Invalid configuration',
        array $context = [],
        ?Throwable $previous = null
    ) {
        $recoverySuggestion = 'Check your configuration values and ensure they match the expected types and ranges.';
        
        parent::__construct(
            $message,
            5001,
            $previous,
            $context,
            $recoverySuggestion
        );
    }
}
