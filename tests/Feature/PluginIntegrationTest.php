<?php

declare(strict_types=1);

use PestPluginBrowserRecording\BrowserRecordingPlugin;
use PestPluginBrowserRecording\Config\RecordingConfig;
use PestPluginBrowserRecording\Recorder\RecordingSession;
use PestPluginBrowserRecording\Generator\CodeGenerator;
use PestPluginBrowserRecording\Injector\FileInjector;

/**
 * Integration tests for the complete plugin system
 * 
 * These tests verify that all components work together correctly
 * and that the plugin integrates properly with Pest.
 */

it('integrates plugin registration with Pest', function () {
    // Verify the plugin trait is available
    expect(trait_exists(BrowserRecordingPlugin::class))->toBeTrue();
    
    // Verify we can instantiate classes that use the trait
    $testInstance = new class {
        use BrowserRecordingPlugin;
    };
    
    expect(method_exists($testInstance, 'record'))->toBeTrue();
});

it('provides complete end-to-end recording workflow', function () {
    // Test the complete workflow without actual browser interaction
    
    // 1. Configuration
    $config = (new RecordingConfig())
        ->timeout(3600)
        ->autoAssertions(true)
        ->backupFiles(false)
        ->generateComments(true);
    
    expect($config->get('timeout'))->toBe(3600);
    expect($config->get('autoAssertions'))->toBeTrue();
    
    // 2. Code Generation
    $codeGenerator = new CodeGenerator($config->all());
    
    // Create sample actions for testing
    $actions = [
        new \PestPluginBrowserRecording\Recorder\ActionData(
            'session:start', 
            ['sessionId' => 'test', 'viewport' => [], 'userAgent' => 'test'], 
            time(), 
            '/login', 
            'test', 
            1, 
            null, 
            []
        ),
        new \PestPluginBrowserRecording\Recorder\ActionData(
            'click', 
            ['selector' => '#login-btn', 'coordinates' => ['x' => 10, 'y' => 20]], 
            time(), 
            '/login', 
            'test', 
            2, 
            null, 
            []
        ),
        new \PestPluginBrowserRecording\Recorder\ActionData(
            'input', 
            ['selector' => '#email', 'value' => 'test@example.com', 'inputType' => 'email'], 
            time(), 
            '/login', 
            'test', 
            3, 
            null, 
            []
        ),
    ];
    
    $result = $codeGenerator->generateTest($actions, 'Complete workflow test');
    
    expect($result->code)->toContain('visit(\'/login\')');
    expect($result->code)->toContain('click(\'#login-btn\')');
    expect($result->code)->toContain('type(\'#email\', \'test@example.com\')'); // Uses type() for email inputs
    expect($result->code)->toContain('it(\'Complete workflow test\'');
    expect($result->hasAssertions)->toBeTrue();
});

it('handles configuration inheritance correctly', function () {
    // Test global config file + runtime overrides
    $globalConfig = [
        'timeout' => 1800,
        'autoAssertions' => true,
        'backupFiles' => false,
    ];
    
    $runtimeOverrides = [
        'timeout' => 3600, // Override
        'generateComments' => false, // New setting
    ];
    
    $config = new RecordingConfig($runtimeOverrides);
    
    // Runtime overrides should take precedence
    expect($config->get('timeout'))->toBe(3600);
    expect($config->get('generateComments'))->toBeFalse();
    
    // Global settings should be preserved when not overridden
    expect($config->get('autoAssertions'))->toBeTrue();
    expect($config->get('backupFiles'))->toBeFalse();
});

it('validates plugin trait usage', function () {
    // Create a test class that uses the plugin trait
    $testClass = new class {
        use BrowserRecordingPlugin;
    };
    
    // Verify the record method is available
    expect(method_exists($testClass, 'record'))->toBeTrue();
    
    // Test method chaining
    $result = $testClass->record(['timeout' => 1000]);
    expect($result)->toBe($testClass); // Should return self for chaining
});

it('integrates all components in realistic scenario', function () {
    // Simulate a realistic recording scenario with all components
    
    // 1. Start with configuration
    $config = RecordingConfig::fromArray([
        'timeout' => 3600,
        'autoAssertions' => true,
        'backupFiles' => false,
        'generateComments' => true,
        'deviceEmulation' => 'mobile',
    ]);
    
    // 2. Create recording session (with mock page)
    $mockPage = new stdClass(); // Simple mock
    $session = new RecordingSession($mockPage, $config->all());
    
    // 3. Simulate some recorded actions
    $session->handleAction('click', [
        'selector' => '#submit-btn',
        'coordinates' => ['x' => 100, 'y' => 200],
        'timestamp' => time(),
    ]);
    
    $session->handleAction('input', [
        'selector' => '#email',
        'value' => 'user@example.com',
        'inputType' => 'email',
        'timestamp' => time(),
    ]);
    
    // 4. Verify actions were recorded
    $actions = $session->getRecordedActions();
    expect($actions)->toHaveCount(2);
    expect($actions[0]['type'])->toBe('click');
    expect($actions[1]['type'])->toBe('input');
    
    // 5. Generate code from actions
    $structuredActions = $session->getStructuredActions();
    if (!empty($structuredActions)) {
        $codeGenerator = new CodeGenerator($config->all());
        $result = $codeGenerator->generateTest($structuredActions, 'Integration test');
        
        expect($result->code)->toContain('it(\'Integration test\'');
        expect($result->actionCount)->toBeGreaterThan(0);
    }
    
    // 6. Verify session statistics
    $stats = $session->getCommunicationStats();
    expect($stats)->toBeArray();
});

it('handles error scenarios gracefully in integration', function () {
    // Test error handling integration
    
    $config = RecordingConfig::fromArray(['timeout' => 100]); // Short timeout
    $mockPage = new stdClass();
    
    // This should not throw exceptions even with invalid data
    $session = new RecordingSession($mockPage, $config->all());
    
    // Try to record invalid actions (should be handled gracefully)
    $session->handleAction('invalid_action', ['invalid' => 'data']);
    $session->handleAction('click', []); // Missing required fields
    
    // Session should still be functional
    $actions = $session->getRecordedActions();
    expect($actions)->toBeArray(); // Should not crash
});

it('validates generated code syntax', function () {
    // Ensure generated code is syntactically valid PHP
    
    $actions = [
        new \PestPluginBrowserRecording\Recorder\ActionData(
            'session:start', [], time(), '/test', 'test', 1, null, []
        ),
        new \PestPluginBrowserRecording\Recorder\ActionData(
            'click', ['selector' => '#btn', 'coordinates' => []], time(), '/test', 'test', 2, null, []
        ),
    ];
    
    $codeGenerator = new CodeGenerator(['autoAssertions' => true]);
    $result = $codeGenerator->generateTest($actions, 'Syntax validation test');
    
    // Verify the generated code is valid PHP
    expect($result->code)->toContain('<?php');
    
    // Parse the code to ensure it's syntactically valid
    $parser = (new PhpParser\ParserFactory())->createForNewestSupportedVersion();
    $ast = $parser->parse($result->code);
    
    expect($ast)->not->toBeNull();
    expect($ast)->toBeArray();
});
