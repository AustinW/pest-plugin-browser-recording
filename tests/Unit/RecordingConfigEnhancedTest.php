<?php

declare(strict_types=1);

use PestPluginBrowserRecording\Config\RecordingConfig;

beforeEach(function () {
    // Create a temporary config file for testing
    $this->tempDir = sys_get_temp_dir() . '/pest-config-test-' . uniqid();
    mkdir($this->tempDir, 0777, true);
    $this->originalCwd = getcwd();
    chdir($this->tempDir);
});

afterEach(function () {
    // Restore original working directory and cleanup
    if (isset($this->originalCwd)) {
        chdir($this->originalCwd);
    }
    
    if (isset($this->tempDir) && is_dir($this->tempDir)) {
        cleanupDirectoryRecursive($this->tempDir);
    }
});

// Helper function for recursive directory cleanup
function cleanupDirectoryRecursive(string $dir): void {
    if (!is_dir($dir)) {
        return;
    }
    
    $files = array_diff(scandir($dir), ['.', '..']);
    foreach ($files as $file) {
        $path = $dir . '/' . $file;
        if (is_dir($path)) {
            cleanupDirectoryRecursive($path);
        } else {
            unlink($path);
        }
    }
    rmdir($dir);
}

it('provides enhanced default configuration', function () {
    $config = RecordingConfig::defaults();
    
    expect($config)
        ->toHaveKey('timeout', 1800)
        ->toHaveKey('autoAssertions', true)
        ->toHaveKey('selectorPriority')
        ->toHaveKey('backupFiles', false) // Updated default
        ->toHaveKey('generateComments', true)
        ->toHaveKey('useStableSelectors', true)
        ->toHaveKey('deviceEmulation', null)
        ->toHaveKey('maxActionsPerSession', 10000)
        ->toHaveKey('includeAriaAttributes', true)
        ->toHaveKey('throttleScrollEvents', true);
});

it('creates config instance with defaults', function () {
    $config = new RecordingConfig();
    
    expect($config->get('timeout'))->toBe(1800);
    expect($config->get('backupFiles'))->toBeFalse();
    expect($config->get('autoAssertions'))->toBeTrue();
    expect($config->get('useStableSelectors'))->toBeTrue();
});

it('creates config instance from array', function () {
    $configArray = [
        'timeout' => 3600,
        'autoAssertions' => false,
        'backupFiles' => true
    ];
    
    $config = RecordingConfig::fromArray($configArray);
    
    expect($config->get('timeout'))->toBe(3600);
    expect($config->get('autoAssertions'))->toBeFalse();
    expect($config->get('backupFiles'))->toBeTrue();
    expect($config->get('generateComments'))->toBeTrue(); // Should keep default
});

it('loads configuration from file', function () {
    // Create a config file
    mkdir('config', 0777, true);
    $configContent = '<?php return [
        "timeout" => 5400,
        "autoAssertions" => false,
        "backupFiles" => true,
        "deviceEmulation" => "mobile"
    ];';
    file_put_contents('config/recording.php', $configContent);
    
    $config = new RecordingConfig();
    
    expect($config->get('timeout'))->toBe(5400);
    expect($config->get('autoAssertions'))->toBeFalse();
    expect($config->get('backupFiles'))->toBeTrue();
    expect($config->get('deviceEmulation'))->toBe('mobile');
});

it('supports fluent API for timeout', function () {
    $config = new RecordingConfig();
    
    $result = $config->timeout(7200);
    
    expect($result)->toBe($config); // Should return self for chaining
    expect($config->get('timeout'))->toBe(7200);
});

it('supports fluent API for boolean options', function () {
    $config = new RecordingConfig();
    
    $result = $config->autoAssertions(false)
                    ->generateComments(false)
                    ->backupFiles(true)
                    ->useStableSelectors(false);
    
    expect($result)->toBe($config); // Should return self for chaining
    expect($config->get('autoAssertions'))->toBeFalse();
    expect($config->get('generateComments'))->toBeFalse();
    expect($config->get('backupFiles'))->toBeTrue();
    expect($config->get('useStableSelectors'))->toBeFalse();
});

it('supports fluent API for array options', function () {
    $config = new RecordingConfig();
    
    $priority = ['data-cy', 'id', 'name'];
    $result = $config->selectorPriority($priority);
    
    expect($result)->toBe($config);
    expect($config->get('selectorPriority'))->toBe($priority);
});

it('supports fluent API for string options', function () {
    $config = new RecordingConfig();
    
    $result = $config->deviceEmulation('mobile')
                    ->colorScheme('dark')
                    ->backupDirectory('/custom/backup/path');
    
    expect($result)->toBe($config);
    expect($config->get('deviceEmulation'))->toBe('mobile');
    expect($config->get('colorScheme'))->toBe('dark');
    expect($config->get('backupDirectory'))->toBe('/custom/backup/path');
});

it('supports fluent API chaining with multiple options', function () {
    $config = new RecordingConfig();
    
    $result = $config->timeout(3600)
                    ->autoAssertions(false)
                    ->backupFiles(true)
                    ->deviceEmulation('mobile')
                    ->includeHoverActions(true)
                    ->maxActionsPerSession(5000);
    
    expect($result)->toBe($config);
    expect($config->get('timeout'))->toBe(3600);
    expect($config->get('autoAssertions'))->toBeFalse();
    expect($config->get('backupFiles'))->toBeTrue();
    expect($config->get('deviceEmulation'))->toBe('mobile');
    expect($config->get('includeHoverActions'))->toBeTrue();
    expect($config->get('maxActionsPerSession'))->toBe(5000);
});

it('validates all enhanced boolean options', function () {
    $booleanOptions = [
        'autoAssertions', 'generateComments', 'useStableSelectors', 'includeAriaAttributes',
        'includeHoverActions', 'captureKeyboardShortcuts', 'recordScrollPosition', 'recordViewportChanges',
        'backupFiles', 'autoCleanupBackups', 'useTypeForInputs', 'chainMethods',
        'throttleScrollEvents', 'debounceInputEvents'
    ];
    
    foreach ($booleanOptions as $option) {
        expect(fn() => new RecordingConfig([$option => 'not-boolean']))
            ->toThrow(InvalidArgumentException::class, "{$option} must be a boolean");
    }
});

it('validates enhanced integer options', function () {
    expect(fn() => new RecordingConfig(['maxBackupsPerFile' => -1]))
        ->toThrow(InvalidArgumentException::class, 'maxBackupsPerFile must be a non-negative integer');
    
    expect(fn() => new RecordingConfig(['maxActionsPerSession' => 'invalid']))
        ->toThrow(InvalidArgumentException::class, 'maxActionsPerSession must be a non-negative integer');
});

it('validates device emulation options', function () {
    expect(fn() => new RecordingConfig(['deviceEmulation' => 'invalid']))
        ->toThrow(InvalidArgumentException::class, 'deviceEmulation must be null, "mobile", or "desktop"');
    
    // Valid options should work
    $config1 = new RecordingConfig(['deviceEmulation' => 'mobile']);
    $config2 = new RecordingConfig(['deviceEmulation' => 'desktop']);
    $config3 = new RecordingConfig(['deviceEmulation' => null]);
    
    expect($config1->get('deviceEmulation'))->toBe('mobile');
    expect($config2->get('deviceEmulation'))->toBe('desktop');
    expect($config3->get('deviceEmulation'))->toBeNull();
});

it('validates color scheme options', function () {
    expect(fn() => new RecordingConfig(['colorScheme' => 'invalid']))
        ->toThrow(InvalidArgumentException::class, 'colorScheme must be null, "dark", or "light"');
    
    // Valid options should work
    $config1 = new RecordingConfig(['colorScheme' => 'dark']);
    $config2 = new RecordingConfig(['colorScheme' => 'light']);
    $config3 = new RecordingConfig(['colorScheme' => null]);
    
    expect($config1->get('colorScheme'))->toBe('dark');
    expect($config2->get('colorScheme'))->toBe('light');
    expect($config3->get('colorScheme'))->toBeNull();
});

it('has() method works correctly', function () {
    $config = new RecordingConfig();
    
    expect($config->has('timeout'))->toBeTrue();
    expect($config->has('backupFiles'))->toBeTrue();
    expect($config->has('nonexistent'))->toBeFalse();
});

it('get() method with default value', function () {
    $config = new RecordingConfig();
    
    expect($config->get('timeout'))->toBe(1800);
    expect($config->get('nonexistent', 'default'))->toBe('default');
    expect($config->get('nonexistent'))->toBeNull();
});

it('all() method returns complete configuration', function () {
    $config = new RecordingConfig(['timeout' => 3600]);
    
    $all = $config->all();
    
    expect($all)->toBeArray();
    expect($all['timeout'])->toBe(3600);
    expect($all['autoAssertions'])->toBeTrue();
    expect(count($all))->toBeGreaterThan(15); // Should have all enhanced options
});

it('merges file config with runtime config correctly', function () {
    // Create config file with some settings
    mkdir('config', 0777, true);
    $configContent = '<?php return [
        "timeout" => 7200,
        "backupFiles" => true,
        "deviceEmulation" => "desktop"
    ];';
    file_put_contents('config/recording.php', $configContent);
    
    // Create instance with runtime overrides
    $config = new RecordingConfig([
        'timeout' => 1800, // Override file setting
        'autoAssertions' => false // New setting
    ]);
    
    expect($config->get('timeout'))->toBe(1800); // Runtime override wins
    expect($config->get('backupFiles'))->toBeTrue(); // From file
    expect($config->get('deviceEmulation'))->toBe('desktop'); // From file
    expect($config->get('autoAssertions'))->toBeFalse(); // Runtime setting
    expect($config->get('generateComments'))->toBeTrue(); // Default preserved
});
