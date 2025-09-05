<?php

declare(strict_types=1);

use PestPluginBrowserRecording\Injector\FileInjector;
use PestPluginBrowserRecording\Injector\InjectionResult;

beforeEach(function () {
    // Create a temporary directory for test files
    $this->tempDir = sys_get_temp_dir() . '/pest-file-injector-test-' . uniqid();
    mkdir($this->tempDir, 0777, true);
});

afterEach(function () {
    // Clean up temporary files
    if (isset($this->tempDir) && is_dir($this->tempDir)) {
        $files = glob($this->tempDir . '/*');
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        rmdir($this->tempDir);
    }
});

it('creates file injector with default configuration', function () {
    $injector = new FileInjector();
    expect($injector)->toBeInstanceOf(FileInjector::class);
});

it('creates file injector with custom configuration', function () {
    $config = [
        'createBackup' => false,
        'backupSuffix' => '.bak',
        'preserveComments' => false
    ];
    
    $injector = new FileInjector($config);
    expect($injector)->toBeInstanceOf(FileInjector::class);
});

it('throws exception for non-existent file', function () {
    $injector = new FileInjector();
    
    expect(fn() => $injector->injectAfterRecordCall('/non/existent/file.php', 'test code'))
        ->toThrow(InvalidArgumentException::class, 'File does not exist');
});

it('successfully injects code after record call', function () {
    $injector = new FileInjector(['createBackup' => false]);
    
    // Create a test file with a record call
    $testFile = $this->tempDir . '/test.php';
    $originalContent = '<?php

it("can record browser actions", function () {
    $page = visit("/test")
        ->record([
            "timeout" => 30,
            "autoAssertions" => true
        ]);
    
    // Test continues here
});
';
    
    file_put_contents($testFile, $originalContent);
    
    $codeToInject = '
    // Generated code from recording
    $page->click("#submit-button")
        ->fill("#email", "test@example.com")
        ->press("Submit");';
    
    $result = $injector->injectAfterRecordCall($testFile, $codeToInject);
    
    expect($result->success)->toBeTrue();
    expect($result->error)->toBeNull();
    expect($result->newSize)->toBeGreaterThan($result->originalSize);
    
    // Verify the content was injected
    $newContent = file_get_contents($testFile);
    expect($newContent)->toContain('click("#submit-button")');
    expect($newContent)->toContain('fill("#email", "test@example.com")');
    expect($newContent)->toContain('press("Submit")');
});

it('creates backup when configured', function () {
    $injector = new FileInjector(['createBackup' => true, 'backupSuffix' => '.test-backup']);
    
    $testFile = $this->tempDir . '/backup-test.php';
    $originalContent = '<?php

it("test with record", function () {
    visit("/test")->record();
});
';
    
    file_put_contents($testFile, $originalContent);
    
    $result = $injector->injectAfterRecordCall($testFile, '$page->click("#btn");');
    
    expect($result->success)->toBeTrue();
    expect($result->backupPath)->not->toBeNull();
    expect(file_exists($result->backupPath))->toBeTrue();
    
    // Verify backup content matches original
    $backupContent = file_get_contents($result->backupPath);
    expect($backupContent)->toBe($originalContent);
});

it('skips backup when disabled', function () {
    $injector = new FileInjector(['createBackup' => false]);
    
    $testFile = $this->tempDir . '/no-backup-test.php';
    $originalContent = '<?php

it("test with record", function () {
    visit("/test")->record();
});
';
    
    file_put_contents($testFile, $originalContent);
    
    $result = $injector->injectAfterRecordCall($testFile, '$page->click("#btn");');
    
    expect($result->success)->toBeTrue();
    expect($result->backupPath)->toBeNull();
});

it('handles files without record call gracefully', function () {
    $injector = new FileInjector(['createBackup' => false]);
    
    $testFile = $this->tempDir . '/no-record.php';
    $originalContent = '<?php

it("regular test without record", function () {
    expect(true)->toBeTrue();
});
';
    
    file_put_contents($testFile, $originalContent);
    
    $result = $injector->injectAfterRecordCall($testFile, '$page->click("#btn");');
    
    expect($result->success)->toBeFalse();
    expect($result->error)->toContain('No ->record() call found');
});

it('handles malformed PHP files gracefully', function () {
    $injector = new FileInjector(['createBackup' => false]);
    
    $testFile = $this->tempDir . '/malformed.php';
    $malformedContent = '<?php

it("test with syntax error", function () {
    visit("/test")->record(
    // Missing closing parenthesis and semicolon
';
    
    file_put_contents($testFile, $malformedContent);
    
    $result = $injector->injectAfterRecordCall($testFile, '$page->click("#btn");');
    
    expect($result->success)->toBeFalse();
    expect($result->error)->toContain('PHP parse error');
});

it('handles malformed injection code gracefully', function () {
    $injector = new FileInjector(['createBackup' => false]);
    
    $testFile = $this->tempDir . '/good-file.php';
    $originalContent = '<?php

it("test with record", function () {
    visit("/test")->record();
});
';
    
    file_put_contents($testFile, $originalContent);
    
    $malformedCode = '$page->click(#btn"); // Missing opening quote';
    
    $result = $injector->injectAfterRecordCall($testFile, $malformedCode);
    
    expect($result->success)->toBeFalse();
    expect($result->error)->toContain('Parse error in code to inject');
});

it('verifies injection when enabled', function () {
    $injector = new FileInjector(['createBackup' => false, 'verifyInjection' => true]);
    
    $testFile = $this->tempDir . '/verify-test.php';
    $originalContent = '<?php

it("test with record", function () {
    visit("/test")->record();
});
';
    
    file_put_contents($testFile, $originalContent);
    
    $result = $injector->injectAfterRecordCall($testFile, '$page->click("#verify-btn");');
    
    expect($result->success)->toBeTrue();
    
    // Verify the content is in the file
    $newContent = file_get_contents($testFile);
    expect($newContent)->toContain('click("#verify-btn")');
});

it('restores from backup correctly', function () {
    $injector = new FileInjector(['createBackup' => true]);
    
    $testFile = $this->tempDir . '/restore-test.php';
    $originalContent = '<?php

it("original test", function () {
    visit("/test")->record();
});
';
    
    file_put_contents($testFile, $originalContent);
    
    $result = $injector->injectAfterRecordCall($testFile, '$page->click("#btn");');
    
    expect($result->success)->toBeTrue();
    expect($result->backupPath)->not->toBeNull();
    
    // Restore from backup
    $restoreResult = $injector->restoreFromBackup($testFile, $result->backupPath);
    expect($restoreResult)->toBeTrue();
    
    // Verify content is restored
    $restoredContent = file_get_contents($testFile);
    expect($restoredContent)->toBe($originalContent);
});

it('cleans up backup files', function () {
    $injector = new FileInjector(['createBackup' => true]);
    
    $testFile = $this->tempDir . '/cleanup-test.php';
    $testContent = '<?php

it("cleanup test", function () {
    $page = visit("/test")->record();
    // Code will be injected here
});
';
    file_put_contents($testFile, $testContent);
    
    $result = $injector->injectAfterRecordCall($testFile, '$page->click("#btn");');
    
    expect($result->success)->toBeTrue();
    expect(file_exists($result->backupPath))->toBeTrue();
    
    // Clean up backup
    $cleanupResult = $injector->cleanupBackup($result->backupPath);
    expect($cleanupResult)->toBeTrue();
    expect(file_exists($result->backupPath))->toBeFalse();
});

it('handles file permission errors gracefully', function () {
    $injector = new FileInjector(['createBackup' => false]);
    
    $testFile = $this->tempDir . '/readonly.php';
    file_put_contents($testFile, '<?php visit("/test")->record();');
    
    // Make file read-only
    chmod($testFile, 0444);
    
    expect(fn() => $injector->injectAfterRecordCall($testFile, '$page->click("#btn");'))
        ->toThrow(InvalidArgumentException::class, 'not writable');
    
    // Restore permissions for cleanup
    chmod($testFile, 0666);
});

it('converts InjectionResult to array correctly', function () {
    $result = new InjectionResult(
        success: true,
        filePath: '/test/file.php',
        backupPath: '/test/file.php.backup',
        originalSize: 100,
        newSize: 150,
        injectionPoint: null,
        error: null
    );
    
    $array = $result->toArray();
    
    expect($array['success'])->toBeTrue();
    expect($array['filePath'])->toBe('/test/file.php');
    expect($array['backupPath'])->toBe('/test/file.php.backup');
    expect($array['originalSize'])->toBe(100);
    expect($array['newSize'])->toBe(150);
    expect($array['sizeDelta'])->toBe(50);
    expect($array['error'])->toBeNull();
});
