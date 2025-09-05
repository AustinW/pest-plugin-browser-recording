<?php

declare(strict_types=1);

namespace PestPluginBrowserRecording\Config;

/**
 * Configuration management for browser recording sessions
 * 
 * This class provides a flexible configuration system with fluent API support
 * for both global and per-test recording options. It integrates with the
 * config/recording.php file and supports runtime configuration overrides.
 */
final class RecordingConfig
{
    /**
     * Default configuration values
     */
    private const DEFAULT_CONFIG = [
        // Session settings
        'timeout' => 1800, // 30 minutes
        'autoAssertions' => true,
        'generateComments' => true,
        
        // Selector settings
        'selectorPriority' => ['data-testid', 'id', 'name', 'class'],
        'useStableSelectors' => true,
        'includeAriaAttributes' => true,
        
        // Recording behavior
        'includeHoverActions' => false,
        'captureKeyboardShortcuts' => false,
        'recordScrollPosition' => false,
        'recordViewportChanges' => true,
        
        // File safety (backups disabled by default)
        'backupFiles' => false,
        'backupDirectory' => '.pest-recording-backups',
        'maxBackupsPerFile' => 10,
        'autoCleanupBackups' => true,
        
        // Code generation
        'useTypeForInputs' => true,
        'chainMethods' => true,
        'deviceEmulation' => null, // 'mobile', 'desktop', null
        'colorScheme' => null, // 'dark', 'light', null
        
        // Performance
        'throttleScrollEvents' => true,
        'debounceInputEvents' => true,
        'maxActionsPerSession' => 10000,
    ];

    /**
     * @var array<string, mixed> Current configuration values
     */
    private array $config;

    /**
     * @var string|null Path to configuration file
     */
    private ?string $configFile;

    public function __construct(array $config = [], ?string $configFile = null)
    {
        $this->configFile = $configFile;
        $this->config = $this->loadConfiguration($config);
    }

    /**
     * Load configuration from file and merge with provided options
     */
    private function loadConfiguration(array $config): array
    {
        $fileConfig = $this->loadConfigFile();
        $merged = array_merge(self::DEFAULT_CONFIG, $fileConfig, $config);
        $this->validateConfiguration($merged);
        return $merged;
    }

    /**
     * Load configuration from file
     */
    private function loadConfigFile(): array
    {
        if ($this->configFile && file_exists($this->configFile)) {
            $config = include $this->configFile;
            return is_array($config) ? $config : [];
        }

        // Try default config file
        $defaultConfigPath = getcwd() . '/config/recording.php';
        if (file_exists($defaultConfigPath)) {
            $config = include $defaultConfigPath;
            return is_array($config) ? $config : [];
        }

        return [];
    }

    // =================================================================
    // FLUENT API METHODS
    // =================================================================

    /**
     * Set session timeout
     */
    public function timeout(int $seconds): self
    {
        $this->config['timeout'] = $seconds;
        return $this;
    }

    /**
     * Enable/disable automatic assertions
     */
    public function autoAssertions(bool $enabled = true): self
    {
        $this->config['autoAssertions'] = $enabled;
        return $this;
    }

    /**
     * Enable/disable comment generation
     */
    public function generateComments(bool $enabled = true): self
    {
        $this->config['generateComments'] = $enabled;
        return $this;
    }

    /**
     * Set selector priority order
     */
    public function selectorPriority(array $priority): self
    {
        $this->config['selectorPriority'] = $priority;
        return $this;
    }

    /**
     * Enable/disable stable selectors
     */
    public function useStableSelectors(bool $enabled = true): self
    {
        $this->config['useStableSelectors'] = $enabled;
        return $this;
    }

    /**
     * Enable/disable ARIA attributes in selectors
     */
    public function includeAriaAttributes(bool $enabled = true): self
    {
        $this->config['includeAriaAttributes'] = $enabled;
        return $this;
    }

    /**
     * Enable/disable hover action recording
     */
    public function includeHoverActions(bool $enabled = true): self
    {
        $this->config['includeHoverActions'] = $enabled;
        return $this;
    }

    /**
     * Enable/disable keyboard shortcut recording
     */
    public function captureKeyboardShortcuts(bool $enabled = true): self
    {
        $this->config['captureKeyboardShortcuts'] = $enabled;
        return $this;
    }

    /**
     * Enable/disable scroll position recording
     */
    public function recordScrollPosition(bool $enabled = true): self
    {
        $this->config['recordScrollPosition'] = $enabled;
        return $this;
    }

    /**
     * Enable/disable viewport change recording
     */
    public function recordViewportChanges(bool $enabled = true): self
    {
        $this->config['recordViewportChanges'] = $enabled;
        return $this;
    }

    /**
     * Enable/disable file backups
     */
    public function backupFiles(bool $enabled = true): self
    {
        $this->config['backupFiles'] = $enabled;
        return $this;
    }

    /**
     * Set backup directory
     */
    public function backupDirectory(string $directory): self
    {
        $this->config['backupDirectory'] = $directory;
        return $this;
    }

    /**
     * Set maximum backups per file
     */
    public function maxBackupsPerFile(int $max): self
    {
        $this->config['maxBackupsPerFile'] = $max;
        return $this;
    }

    /**
     * Enable/disable auto cleanup of old backups
     */
    public function autoCleanupBackups(bool $enabled = true): self
    {
        $this->config['autoCleanupBackups'] = $enabled;
        return $this;
    }

    /**
     * Enable/disable using type() instead of fill() for inputs
     */
    public function useTypeForInputs(bool $enabled = true): self
    {
        $this->config['useTypeForInputs'] = $enabled;
        return $this;
    }

    /**
     * Enable/disable method chaining in generated code
     */
    public function chainMethods(bool $enabled = true): self
    {
        $this->config['chainMethods'] = $enabled;
        return $this;
    }

    /**
     * Set device emulation mode
     */
    public function deviceEmulation(?string $device): self
    {
        $this->config['deviceEmulation'] = $device;
        return $this;
    }

    /**
     * Set color scheme for testing
     */
    public function colorScheme(?string $scheme): self
    {
        $this->config['colorScheme'] = $scheme;
        return $this;
    }

    /**
     * Enable/disable scroll event throttling
     */
    public function throttleScrollEvents(bool $enabled = true): self
    {
        $this->config['throttleScrollEvents'] = $enabled;
        return $this;
    }

    /**
     * Enable/disable input event debouncing
     */
    public function debounceInputEvents(bool $enabled = true): self
    {
        $this->config['debounceInputEvents'] = $enabled;
        return $this;
    }

    /**
     * Set maximum actions per session
     */
    public function maxActionsPerSession(int $max): self
    {
        $this->config['maxActionsPerSession'] = $max;
        return $this;
    }

    // =================================================================
    // GETTER METHODS
    // =================================================================

    /**
     * Get a specific configuration value
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->config[$key] ?? $default;
    }

    /**
     * Get all configuration values
     */
    public function all(): array
    {
        return $this->config;
    }

    /**
     * Check if a configuration key exists
     */
    public function has(string $key): bool
    {
        return array_key_exists($key, $this->config);
    }

    // =================================================================
    // STATIC HELPER METHODS (for backward compatibility)
    // =================================================================

    /**
     * Create a new configuration instance from array
     */
    public static function fromArray(array $config): self
    {
        return new self($config);
    }

    /**
     * Create configuration from config file
     */
    public static function fromFile(string $configFile): self
    {
        return new self([], $configFile);
    }

    /**
     * Merge user options with default configuration
     *
     * @param array<string, mixed> $options User-provided options
     * @return array<string, mixed> Merged configuration
     */
    public static function merge(array $options = []): array
    {
        return array_merge(self::DEFAULT_CONFIG, $options);
    }

    /**
     * Get default configuration
     *
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return self::DEFAULT_CONFIG;
    }

    /**
     * Validate configuration options
     *
     * @param array<string, mixed> $config Configuration to validate
     * @throws \InvalidArgumentException If configuration is invalid
     */
    public static function validate(array $config): void
    {
        $instance = new self();
        $instance->validateConfiguration($config);
    }

    /**
     * Validate configuration array
     */
    private function validateConfiguration(array $config): void
    {
        // Validate timeout
        if (isset($config['timeout']) && (!is_int($config['timeout']) || $config['timeout'] <= 0)) {
            throw new \InvalidArgumentException('Timeout must be a positive integer');
        }

        // Validate selector priority
        if (isset($config['selectorPriority']) && !is_array($config['selectorPriority'])) {
            throw new \InvalidArgumentException('SelectorPriority must be an array');
        }

        // Validate boolean options
        $booleanOptions = [
            'autoAssertions', 'generateComments', 'useStableSelectors', 'includeAriaAttributes',
            'includeHoverActions', 'captureKeyboardShortcuts', 'recordScrollPosition', 'recordViewportChanges',
            'backupFiles', 'autoCleanupBackups', 'useTypeForInputs', 'chainMethods',
            'throttleScrollEvents', 'debounceInputEvents'
        ];

        foreach ($booleanOptions as $option) {
            if (isset($config[$option]) && !is_bool($config[$option])) {
                throw new \InvalidArgumentException("{$option} must be a boolean");
            }
        }

        // Validate integer options
        $integerOptions = ['maxBackupsPerFile', 'maxActionsPerSession'];
        foreach ($integerOptions as $option) {
            if (isset($config[$option]) && (!is_int($config[$option]) || $config[$option] < 0)) {
                throw new \InvalidArgumentException("{$option} must be a non-negative integer");
            }
        }

        // Validate string options
        if (isset($config['backupDirectory']) && !is_string($config['backupDirectory'])) {
            throw new \InvalidArgumentException('backupDirectory must be a string');
        }

        // Validate enum options
        if (isset($config['deviceEmulation']) && 
            !in_array($config['deviceEmulation'], [null, 'mobile', 'desktop'])) {
            throw new \InvalidArgumentException('deviceEmulation must be null, "mobile", or "desktop"');
        }

        if (isset($config['colorScheme']) && 
            !in_array($config['colorScheme'], [null, 'dark', 'light'])) {
            throw new \InvalidArgumentException('colorScheme must be null, "dark", or "light"');
        }
    }
}
