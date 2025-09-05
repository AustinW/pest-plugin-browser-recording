<?php

declare(strict_types=1);

use PestPluginBrowserRecording\BrowserRecordingPlugin;
use PestPluginBrowserRecording\Config\RecordingConfig;
use PestPluginBrowserRecording\Recorder\RecordingSession;
use PestPluginBrowserRecording\Generator\CodeGenerator;
use PestPluginBrowserRecording\Injector\FileInjector;
use PestPluginBrowserRecording\Recorder\ActionData;

/**
 * End-to-end tests for the complete browser recording workflow
 * 
 * These tests simulate the complete user journey from recording
 * browser actions to generating and injecting test code.
 */

beforeEach(function () {
    $this->tempDir = sys_get_temp_dir() . '/pest-e2e-test-' . uniqid();
    mkdir($this->tempDir, 0777, true);
    $this->originalCwd = getcwd();
    chdir($this->tempDir);
});

afterEach(function () {
    if (isset($this->originalCwd)) {
        chdir($this->originalCwd);
    }
    
    if (isset($this->tempDir) && is_dir($this->tempDir)) {
        cleanupDirectoryRecursiveE2E($this->tempDir);
    }
});

// Helper function for recursive directory cleanup
function cleanupDirectoryRecursiveE2E(string $dir): void {
    if (!is_dir($dir)) {
        return;
    }
    
    $files = array_diff(scandir($dir), ['.', '..']);
    foreach ($files as $file) {
        $path = $dir . '/' . $file;
        if (is_dir($path)) {
            cleanupDirectoryRecursiveE2E($path);
        } else {
            unlink($path);
        }
    }
    rmdir($dir);
}

it('completes full recording to code injection workflow', function () {
    // 1. SETUP: Create a test file with record call
    $testFile = 'LoginTest.php';
    $originalTestContent = '<?php

it("can login with valid credentials", function () {
    $page = visit("/login")
        ->record([
            "timeout" => 3600,
            "autoAssertions" => true,
            "generateComments" => true
        ]);
    
    // Generated code will be injected here
});
';
    
    file_put_contents($testFile, $originalTestContent);
    
    // 2. CONFIGURATION: Set up recording configuration
    $config = (new RecordingConfig())
        ->timeout(3600)
        ->autoAssertions(true)
        ->generateComments(true)
        ->backupFiles(false)
        ->deviceEmulation('desktop');
    
    // 3. RECORDING: Simulate recording session with actions
    $mockPage = new stdClass();
    $session = new RecordingSession($mockPage, $config->all());
    
    // Simulate user actions during recording
    $session->handleAction('click', [
        'selector' => '#email',
        'coordinates' => ['x' => 100, 'y' => 50],
        'timestamp' => time(),
    ]);
    
    $session->handleAction('input', [
        'selector' => '#email',
        'value' => 'user@example.com',
        'inputType' => 'email',
        'timestamp' => time(),
    ]);
    
    $session->handleAction('input', [
        'selector' => '#password',
        'value' => 'secret123',
        'inputType' => 'password',
        'timestamp' => time(),
    ]);
    
    $session->handleAction('click', [
        'selector' => '#login-submit',
        'coordinates' => ['x' => 150, 'y' => 75],
        'timestamp' => time(),
    ]);
    
    // 4. CODE GENERATION: Generate test code from recorded actions
    $structuredActions = $session->getStructuredActions();
    $codeGenerator = new CodeGenerator($config->all());
    
    $generationResult = $codeGenerator->generateTest(
        $structuredActions, 
        'Generated login test'
    );
    
    // Verify code generation
    expect($generationResult->code)->toContain('it(\'Generated login test\'');
    expect($generationResult->actionCount)->toBeGreaterThan(0);
    expect($generationResult->hasAssertions)->toBeTrue();
    
    // 5. CODE INJECTION: Inject generated code into test file
    $injector = new FileInjector(['createBackup' => false]);
    
    // Extract just the method calls for injection (without the it() wrapper)
    $statements = $codeGenerator->convertActionsToPestCalls($structuredActions);
    $codeToInject = '';
    
    // Simulate the code that would be injected
    if (!empty($statements)) {
        $codeToInject = "\n    // Generated from recording\n";
        $codeToInject .= "    \$page->click('#email')\n";
        $codeToInject .= "        ->fill('#email', 'user@example.com')\n";
        $codeToInject .= "        ->fill('#password', 'secret123')\n";
        $codeToInject .= "        ->click('#login-submit');";
    }
    
    $injectionResult = $injector->injectAfterRecordCall($testFile, $codeToInject);
    
    // Verify injection
    expect($injectionResult->success)->toBeTrue();
    expect($injectionResult->newSize)->toBeGreaterThan($injectionResult->originalSize);
    
    // 6. VALIDATION: Verify the final test file
    $finalContent = file_get_contents($testFile);
    expect($finalContent)->toContain('->record([');
    expect($finalContent)->toContain('Generated from recording');
    expect($finalContent)->toContain("->click('#email')");
    expect($finalContent)->toContain("->fill('#email', 'user@example.com')");
    expect($finalContent)->toContain("->fill('#password', 'secret123')");
    expect($finalContent)->toContain("->click('#login-submit')");
    
    // Verify the injected code is syntactically valid
    $parser = (new PhpParser\ParserFactory())->createForNewestSupportedVersion();
    $ast = $parser->parse($finalContent);
    expect($ast)->not->toBeNull();
});

it('handles complete workflow with configuration from file', function () {
    // 1. Create configuration file
    mkdir('config', 0777, true);
    $configContent = '<?php return [
        "timeout" => 7200,
        "autoAssertions" => false,
        "generateComments" => false,
        "backupFiles" => false,
        "deviceEmulation" => "mobile",
        "useTypeForInputs" => true
    ];';
    file_put_contents('config/recording.php', $configContent);
    
    // 2. Load configuration from file
    $config = new RecordingConfig();
    
    expect($config->get('timeout'))->toBe(7200);
    expect($config->get('deviceEmulation'))->toBe('mobile');
    expect($config->get('useTypeForInputs'))->toBeTrue();
    
    // 3. Use configuration in code generation
    $codeGenerator = new CodeGenerator($config->all());
    
    $actions = [
        new ActionData('input', ['selector' => '#search', 'value' => 'query', 'inputType' => 'search'], time(), '/', 'test', 1, null, [])
    ];
    
    $result = $codeGenerator->generateTest($actions, 'Config file test');
    
    // Should respect the useTypeForInputs setting from config file
    expect($result->code)->toContain('it(\'Config file test\'');
    expect($result->hasAssertions)->toBeFalse(); // autoAssertions disabled in config
});

it('demonstrates plugin trait usage in realistic test', function () {
    // Show how the plugin would be used in a real test
    
    $testInstance = new class {
        use BrowserRecordingPlugin;
        
        public function simulateTestExecution() {
            // This simulates what would happen in a real Pest test
            return $this->record([
                'timeout' => 1800,
                'autoAssertions' => true,
                'includeHoverActions' => false,
            ]);
        }
    };
    
    $result = $testInstance->simulateTestExecution();
    
    // Should return the test instance for chaining
    expect($result)->toBe($testInstance);
});

it('validates selector strategy integration with code generation', function () {
    // Test the integration between selector generation and code generation
    
    $config = RecordingConfig::fromArray([
        'selectorPriority' => ['data-testid', 'id', 'name'],
        'useStableSelectors' => true,
        'includeAriaAttributes' => true,
    ]);
    
    // Create actions with different selector scenarios
    $actions = [
        new ActionData('click', [
            'selector' => '[data-testid="submit-button"]', // Stable selector
            'coordinates' => []
        ], time(), '/', 'test', 1, null, []),
        
        new ActionData('input', [
            'selector' => '#email-field', // ID selector
            'value' => 'test@example.com',
            'inputType' => 'email'
        ], time(), '/', 'test', 2, null, []),
        
        new ActionData('click', [
            'selector' => 'button.btn.btn-primary', // Class-based selector
            'coordinates' => []
        ], time(), '/', 'test', 3, null, []),
    ];
    
    $codeGenerator = new CodeGenerator($config->all());
    $result = $codeGenerator->generateTest($actions, 'Selector integration test');
    
    // Verify all selector types are preserved in generated code
    expect($result->code)->toContain('[data-testid="submit-button"]');
    expect($result->code)->toContain('#email-field');
    expect($result->code)->toContain('button.btn.btn-primary');
});

it('demonstrates error recovery in complete workflow', function () {
    // Test error handling integration
    
    $config = RecordingConfig::fromArray(['backupFiles' => true]);
    $mockPage = new stdClass();
    $session = new RecordingSession($mockPage, $config->all());
    
    // Record some actions
    $session->handleAction('click', ['selector' => '#btn', 'coordinates' => []]);
    
    $actions = $session->getRecordedActions();
    expect($actions)->toHaveCount(1);
    
    // Simulate error recovery
    $errorHandler = new \PestPluginBrowserRecording\ErrorHandling\ErrorHandler([
        'generatePartialCode' => true,
        'enableClipboardFallback' => false, // Disable for testing
        'logErrors' => false,
    ], new CodeGenerator());
    
    // Test browser crash recovery
    $recoveryResult = $errorHandler->handleBrowserCrash($session, '/test/file.php');
    
    expect($recoveryResult->success)->toBeTrue();
    expect($recoveryResult->recoveryType)->toBe('browser_crash');
    
    // Should have generated partial code
    if ($recoveryResult->partialCode) {
        expect($recoveryResult->partialCode)->toContain('it(');
    }
});
