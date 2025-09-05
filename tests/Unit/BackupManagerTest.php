<?php

declare(strict_types=1);

use PestPluginBrowserRecording\Injector\BackupManager;
use PestPluginBrowserRecording\Injector\BackupResult;
use PestPluginBrowserRecording\Injector\RestoreResult;
use PestPluginBrowserRecording\Injector\CleanupResult;

beforeEach(function () {
    // Create a temporary directory for test files
    $this->tempDir = sys_get_temp_dir() . '/pest-backup-manager-test-' . uniqid();
    mkdir($this->tempDir, 0777, true);
    
    // Change to temp directory for relative path testing
    $this->originalCwd = getcwd();
    chdir($this->tempDir);
});

afterEach(function () {
    // Restore original working directory
    if (isset($this->originalCwd)) {
        chdir($this->originalCwd);
    }
    
    // Clean up temporary files
    if (isset($this->tempDir) && is_dir($this->tempDir)) {
        cleanupDirectory($this->tempDir);
    }
});

it('creates backup manager with default configuration (disabled)', function () {
    $manager = new BackupManager();
    
    expect($manager->isEnabled())->toBeFalse();
    
    $stats = $manager->getStatistics();
    expect($stats['enabled'])->toBeFalse();
    expect($stats['totalBackups'])->toBe(0);
});

it('creates backup manager with backups enabled', function () {
    $manager = new BackupManager(['enabled' => true]);
    
    expect($manager->isEnabled())->toBeTrue();
    
    $stats = $manager->getStatistics();
    expect($stats['enabled'])->toBeTrue();
    expect($stats['backupDir'])->not->toBeNull();
});

it('skips backup creation when disabled', function () {
    $manager = new BackupManager(['enabled' => false]);
    
    $testFile = 'test.php';
    file_put_contents($testFile, '<?php echo "test";');
    
    $result = $manager->createBackup($testFile);
    
    expect($result->success)->toBeTrue();
    expect($result->skipped)->toBeTrue();
    expect($result->backupPath)->toBeNull();
});

it('creates backup when enabled', function () {
    $manager = new BackupManager(['enabled' => true]);
    
    $testFile = 'backup-test.php';
    $content = '<?php echo "backup test";';
    file_put_contents($testFile, $content);
    
    $result = $manager->createBackup($testFile);
    
    expect($result->success)->toBeTrue();
    expect($result->skipped)->toBeFalse();
    expect($result->backupPath)->not->toBeNull();
    expect(file_exists($result->backupPath))->toBeTrue();
    
    // Verify backup content matches original
    $backupContent = file_get_contents($result->backupPath);
    expect($backupContent)->toBe($content);
});

it('creates multiple backups with unique names', function () {
    $manager = new BackupManager(['enabled' => true]);
    
    $testFile = 'multi-backup.php';
    file_put_contents($testFile, '<?php echo "version 1";');
    
    $result1 = $manager->createBackup($testFile);
    
    // Modify file and create another backup
    file_put_contents($testFile, '<?php echo "version 2";');
    usleep(1000); // Ensure different timestamp
    $result2 = $manager->createBackup($testFile);
    
    expect($result1->success)->toBeTrue();
    expect($result2->success)->toBeTrue();
    expect($result1->backupPath)->not->toBe($result2->backupPath);
    
    $backups = $manager->getBackupsForFile($testFile);
    expect($backups)->toHaveCount(2);
});

it('tracks backups correctly', function () {
    $manager = new BackupManager(['enabled' => true]);
    
    $testFile1 = 'track1.php';
    $testFile2 = 'track2.php';
    
    file_put_contents($testFile1, '<?php echo "file 1";');
    file_put_contents($testFile2, '<?php echo "file 2";');
    
    $manager->createBackup($testFile1);
    $manager->createBackup($testFile1); // Second backup for same file
    $manager->createBackup($testFile2);
    
    expect($manager->getBackupsForFile($testFile1))->toHaveCount(2);
    expect($manager->getBackupsForFile($testFile2))->toHaveCount(1);
    
    $stats = $manager->getStatistics();
    expect($stats['totalBackups'])->toBe(3);
    expect($stats['totalFiles'])->toBe(2);
});

it('restores from backup when enabled', function () {
    $manager = new BackupManager(['enabled' => true]);
    
    $testFile = 'restore-test.php';
    $originalContent = '<?php echo "original";';
    $modifiedContent = '<?php echo "modified";';
    
    file_put_contents($testFile, $originalContent);
    $backupResult = $manager->createBackup($testFile);
    
    // Modify the file
    file_put_contents($testFile, $modifiedContent);
    expect(file_get_contents($testFile))->toBe($modifiedContent);
    
    // Restore from backup
    $restoreResult = $manager->restoreFromBackup($testFile);
    
    expect($restoreResult->success)->toBeTrue();
    expect($restoreResult->backupPath)->toBe($backupResult->backupPath);
    
    // Verify content is restored
    $restoredContent = file_get_contents($testFile);
    expect($restoredContent)->toBe($originalContent);
});

it('fails restore when backups are disabled', function () {
    $manager = new BackupManager(['enabled' => false]);
    
    $testFile = 'no-restore.php';
    file_put_contents($testFile, '<?php echo "test";');
    
    $result = $manager->restoreFromBackup($testFile);
    
    expect($result->success)->toBeFalse();
    expect($result->error)->toBe('Backups are disabled');
});

it('cleans up old backups automatically', function () {
    $manager = new BackupManager([
        'enabled' => true,
        'maxBackupsPerFile' => 2,
        'autoCleanup' => true
    ]);
    
    $testFile = 'cleanup-auto.php';
    file_put_contents($testFile, '<?php echo "test";');
    
    // Create more backups than the limit
    $manager->createBackup($testFile);
    usleep(1000);
    $manager->createBackup($testFile);
    usleep(1000);
    $manager->createBackup($testFile); // This should trigger cleanup
    
    $backups = $manager->getBackupsForFile($testFile);
    expect($backups)->toHaveCount(2); // Should be limited to maxBackupsPerFile
});

it('cleans up backups for specific file', function () {
    $manager = new BackupManager(['enabled' => true]);
    
    $testFile1 = 'cleanup1.php';
    $testFile2 = 'cleanup2.php';
    
    file_put_contents($testFile1, '<?php echo "file 1";');
    file_put_contents($testFile2, '<?php echo "file 2";');
    
    $manager->createBackup($testFile1);
    $manager->createBackup($testFile2);
    
    expect($manager->getBackupsForFile($testFile1))->toHaveCount(1);
    expect($manager->getBackupsForFile($testFile2))->toHaveCount(1);
    
    // Clean up backups for file 1 only
    $cleanupResult = $manager->cleanupBackupsForFile($testFile1);
    
    expect($cleanupResult->success)->toBeTrue();
    expect($cleanupResult->filesRemoved)->toBe(1);
    expect($manager->getBackupsForFile($testFile1))->toBeEmpty();
    expect($manager->getBackupsForFile($testFile2))->toHaveCount(1);
});

it('cleans up all backups', function () {
    $manager = new BackupManager(['enabled' => true]);
    
    $testFile1 = 'cleanup-all1.php';
    $testFile2 = 'cleanup-all2.php';
    
    file_put_contents($testFile1, '<?php echo "file 1";');
    file_put_contents($testFile2, '<?php echo "file 2";');
    
    $manager->createBackup($testFile1);
    $manager->createBackup($testFile2);
    
    $stats = $manager->getStatistics();
    expect($stats['totalBackups'])->toBe(2);
    
    $cleanupResult = $manager->cleanupAllBackups();
    
    expect($cleanupResult->success)->toBeTrue();
    expect($cleanupResult->filesRemoved)->toBe(2);
    
    $newStats = $manager->getStatistics();
    expect($newStats['totalBackups'])->toBe(0);
});

it('can be enabled and disabled dynamically', function () {
    $manager = new BackupManager(['enabled' => false]);
    
    expect($manager->isEnabled())->toBeFalse();
    
    // Enable backups
    $manager->enable();
    expect($manager->isEnabled())->toBeTrue();
    
    // Disable backups
    $manager->disable();
    expect($manager->isEnabled())->toBeFalse();
});

it('handles file validation errors gracefully', function () {
    $manager = new BackupManager(['enabled' => true]);
    
    // Test with non-existent file
    $result = $manager->createBackup('/non/existent/file.php');
    
    expect($result->success)->toBeFalse();
    expect($result->error)->toContain('File does not exist');
});

it('converts BackupResult to array correctly', function () {
    $result = new BackupResult(
        success: true,
        originalPath: '/test/file.php',
        backupPath: '/backups/file.backup',
        timestamp: 1640995200,
        size: 1024,
        skipped: false,
        error: null
    );
    
    $array = $result->toArray();
    
    expect($array)->toBe([
        'success' => true,
        'originalPath' => '/test/file.php',
        'backupPath' => '/backups/file.backup',
        'timestamp' => 1640995200,
        'size' => 1024,
        'skipped' => false,
        'error' => null,
    ]);
});

it('converts RestoreResult to array correctly', function () {
    $result = new RestoreResult(
        success: true,
        filePath: '/test/file.php',
        backupPath: '/backups/file.backup',
        error: null
    );
    
    $array = $result->toArray();
    
    expect($array)->toBe([
        'success' => true,
        'filePath' => '/test/file.php',
        'backupPath' => '/backups/file.backup',
        'error' => null,
    ]);
});

it('converts CleanupResult to array correctly', function () {
    $result = new CleanupResult(
        success: true,
        filesRemoved: 5,
        errors: []
    );
    
    $array = $result->toArray();
    
    expect($array)->toBe([
        'success' => true,
        'filesRemoved' => 5,
        'errors' => [],
    ]);
});

// Helper function to clean up directories recursively
function cleanupDirectory(string $dir): void {
    if (!is_dir($dir)) {
        return;
    }
    
    $files = array_diff(scandir($dir), ['.', '..']);
    foreach ($files as $file) {
        $path = $dir . '/' . $file;
        if (is_dir($path)) {
            cleanupDirectory($path);
        } else {
            unlink($path);
        }
    }
    rmdir($dir);
}
