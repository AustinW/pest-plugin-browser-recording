<?php

declare(strict_types=1);

namespace PestPluginBrowserRecording\Injector;

use InvalidArgumentException;
use RuntimeException;

/**
 * Manages file backups for safe code injection operations
 * 
 * This class provides a robust backup and restore system for test files,
 * ensuring developers can safely recover from failed injections while
 * keeping backups disabled by default for a smooth development experience.
 */
final class BackupManager
{
    /**
     * Default backup directory name
     */
    private const DEFAULT_BACKUP_DIR = '.pest-recording-backups';

    /**
     * Maximum number of backups to keep per file
     */
    private const MAX_BACKUPS_PER_FILE = 10;

    /**
     * @var array<string, mixed> Backup configuration
     */
    private array $config;

    /**
     * @var string Backup directory path
     */
    private string $backupDir;

    /**
     * @var array<string, array<string>> Track backups by file
     */
    private array $fileBackups = [];

    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'enabled' => false, // Backups disabled by default
            'backupDir' => self::DEFAULT_BACKUP_DIR,
            'timestampFormat' => 'Y-m-d_H-i-s',
            'maxBackupsPerFile' => self::MAX_BACKUPS_PER_FILE,
            'autoCleanup' => true,
            'compressionEnabled' => false,
            'maxFileSize' => 10 * 1024 * 1024, // 10MB limit
        ], $config);

        $this->initializeBackupDirectory();
    }

    /**
     * Create a backup of the specified file
     * 
     * @param string $filePath Path to the file to backup
     * @return BackupResult Result of the backup operation
     */
    public function createBackup(string $filePath): BackupResult
    {
        if (!$this->config['enabled']) {
            return new BackupResult(
                success: true,
                originalPath: $filePath,
                backupPath: null,
                timestamp: time(),
                size: 0,
                skipped: true,
                error: null
            );
        }

        try {
            $this->validateFileForBackup($filePath);

            $backupPath = $this->generateBackupPath($filePath);
            $fileSize = filesize($filePath);
            
            if ($fileSize === false) {
                throw new RuntimeException("Cannot determine file size: {$filePath}");
            }

            // Create the backup
            $success = copy($filePath, $backupPath);
            if (!$success) {
                throw new RuntimeException("Failed to create backup: {$backupPath}");
            }

            // Track the backup
            $this->trackBackup($filePath, $backupPath);

            // Cleanup old backups if auto-cleanup is enabled
            if ($this->config['autoCleanup']) {
                $this->cleanupOldBackups($filePath);
            }

            return new BackupResult(
                success: true,
                originalPath: $filePath,
                backupPath: $backupPath,
                timestamp: time(),
                size: $fileSize,
                skipped: false,
                error: null
            );

        } catch (\Exception $e) {
            return new BackupResult(
                success: false,
                originalPath: $filePath,
                backupPath: null,
                timestamp: time(),
                size: 0,
                skipped: false,
                error: $e->getMessage()
            );
        }
    }

    /**
     * Restore a file from its most recent backup
     * 
     * @param string $filePath Path to the file to restore
     * @return RestoreResult Result of the restore operation
     */
    public function restoreFromBackup(string $filePath): RestoreResult
    {
        if (!$this->config['enabled']) {
            return new RestoreResult(
                success: false,
                filePath: $filePath,
                backupPath: null,
                error: 'Backups are disabled'
            );
        }

        try {
            $latestBackup = $this->getLatestBackup($filePath);
            
            if ($latestBackup === null) {
                throw new RuntimeException("No backup found for file: {$filePath}");
            }

            if (!file_exists($latestBackup)) {
                throw new RuntimeException("Backup file does not exist: {$latestBackup}");
            }

            $success = copy($latestBackup, $filePath);
            if (!$success) {
                throw new RuntimeException("Failed to restore from backup: {$latestBackup}");
            }

            return new RestoreResult(
                success: true,
                filePath: $filePath,
                backupPath: $latestBackup,
                error: null
            );

        } catch (\Exception $e) {
            return new RestoreResult(
                success: false,
                filePath: $filePath,
                backupPath: null,
                error: $e->getMessage()
            );
        }
    }

    /**
     * List all backups for a specific file
     * 
     * @param string $filePath Path to the original file
     * @return array<string> Array of backup file paths
     */
    public function getBackupsForFile(string $filePath): array
    {
        if (!$this->config['enabled']) {
            return [];
        }

        $fileKey = $this->getFileKey($filePath);
        return $this->fileBackups[$fileKey] ?? [];
    }

    /**
     * Clean up all backups for a specific file
     * 
     * @param string $filePath Path to the original file
     * @return CleanupResult Result of the cleanup operation
     */
    public function cleanupBackupsForFile(string $filePath): CleanupResult
    {
        if (!$this->config['enabled']) {
            return new CleanupResult(
                success: true,
                filesRemoved: 0,
                errors: []
            );
        }

        $backups = $this->getBackupsForFile($filePath);
        $removed = 0;
        $errors = [];

        foreach ($backups as $backupPath) {
            if (file_exists($backupPath)) {
                if (unlink($backupPath)) {
                    $removed++;
                } else {
                    $errors[] = "Failed to remove backup: {$backupPath}";
                }
            }
        }

        // Clear tracking
        $fileKey = $this->getFileKey($filePath);
        unset($this->fileBackups[$fileKey]);

        return new CleanupResult(
            success: empty($errors),
            filesRemoved: $removed,
            errors: $errors
        );
    }

    /**
     * Clean up all backups in the backup directory
     * 
     * @return CleanupResult Result of the cleanup operation
     */
    public function cleanupAllBackups(): CleanupResult
    {
        if (!$this->config['enabled'] || !is_dir($this->backupDir)) {
            return new CleanupResult(
                success: true,
                filesRemoved: 0,
                errors: []
            );
        }

        $removed = 0;
        $errors = [];

        $files = glob($this->backupDir . '/*');
        if ($files !== false) {
            foreach ($files as $file) {
                if (is_file($file)) {
                    if (unlink($file)) {
                        $removed++;
                    } else {
                        $errors[] = "Failed to remove backup: {$file}";
                    }
                }
            }
        }

        // Clear all tracking
        $this->fileBackups = [];

        return new CleanupResult(
            success: empty($errors),
            filesRemoved: $removed,
            errors: $errors
        );
    }

    /**
     * Get backup statistics
     * 
     * @return array<string, mixed> Backup statistics
     */
    public function getStatistics(): array
    {
        if (!$this->config['enabled']) {
            return [
                'enabled' => false,
                'totalBackups' => 0,
                'totalFiles' => 0,
                'backupDir' => null,
                'diskUsage' => 0,
            ];
        }

        $totalBackups = array_sum(array_map('count', $this->fileBackups));
        $totalFiles = count($this->fileBackups);
        $diskUsage = $this->calculateBackupDiskUsage();

        return [
            'enabled' => true,
            'totalBackups' => $totalBackups,
            'totalFiles' => $totalFiles,
            'backupDir' => $this->backupDir,
            'diskUsage' => $diskUsage,
            'config' => [
                'maxBackupsPerFile' => $this->config['maxBackupsPerFile'],
                'autoCleanup' => $this->config['autoCleanup'],
                'maxFileSize' => $this->config['maxFileSize'],
            ],
        ];
    }

    /**
     * Enable backups with optional configuration override
     * 
     * @param array<string, mixed> $config Optional configuration override
     */
    public function enable(array $config = []): void
    {
        $this->config = array_merge($this->config, $config, ['enabled' => true]);
        $this->initializeBackupDirectory();
    }

    /**
     * Disable backups
     */
    public function disable(): void
    {
        $this->config['enabled'] = false;
    }

    /**
     * Check if backups are enabled
     */
    public function isEnabled(): bool
    {
        return $this->config['enabled'];
    }

    /**
     * Initialize backup directory
     */
    private function initializeBackupDirectory(): void
    {
        if (!$this->config['enabled']) {
            return;
        }

        $this->backupDir = $this->config['backupDir'];
        
        // Make backup directory relative to current working directory if not absolute
        if (!str_starts_with($this->backupDir, '/')) {
            $this->backupDir = getcwd() . '/' . $this->backupDir;
        }

        if (!is_dir($this->backupDir)) {
            if (!mkdir($this->backupDir, 0755, true)) {
                throw new RuntimeException("Failed to create backup directory: {$this->backupDir}");
            }
        }

        if (!is_writable($this->backupDir)) {
            throw new RuntimeException("Backup directory is not writable: {$this->backupDir}");
        }
    }

    /**
     * Validate file for backup
     * 
     * @param string $filePath
     * @throws InvalidArgumentException|RuntimeException
     */
    private function validateFileForBackup(string $filePath): void
    {
        if (!file_exists($filePath)) {
            throw new InvalidArgumentException("File does not exist: {$filePath}");
        }

        if (!is_readable($filePath)) {
            throw new InvalidArgumentException("File is not readable: {$filePath}");
        }

        $fileSize = filesize($filePath);
        if ($fileSize === false) {
            throw new RuntimeException("Cannot determine file size: {$filePath}");
        }

        if ($fileSize > $this->config['maxFileSize']) {
            throw new InvalidArgumentException("File exceeds maximum backup size: {$filePath}");
        }
    }

    /**
     * Generate a unique backup path for a file
     */
    private function generateBackupPath(string $filePath): string
    {
        $filename = basename($filePath);
        $timestamp = date($this->config['timestampFormat']);
        $uniqueId = substr(md5($filePath . microtime()), 0, 8);
        
        $backupFilename = "{$filename}.{$timestamp}.{$uniqueId}.backup";
        return $this->backupDir . '/' . $backupFilename;
    }

    /**
     * Track a backup for a file
     */
    private function trackBackup(string $filePath, string $backupPath): void
    {
        $fileKey = $this->getFileKey($filePath);
        
        if (!isset($this->fileBackups[$fileKey])) {
            $this->fileBackups[$fileKey] = [];
        }

        $this->fileBackups[$fileKey][] = $backupPath;
        
        // Sort by creation time (newest first)
        usort($this->fileBackups[$fileKey], function ($a, $b) {
            return filemtime($b) <=> filemtime($a);
        });
    }

    /**
     * Get the latest backup for a file
     */
    private function getLatestBackup(string $filePath): ?string
    {
        $backups = $this->getBackupsForFile($filePath);
        return $backups[0] ?? null;
    }

    /**
     * Clean up old backups for a file
     */
    private function cleanupOldBackups(string $filePath): void
    {
        $backups = $this->getBackupsForFile($filePath);
        $toRemove = array_slice($backups, $this->config['maxBackupsPerFile']);

        foreach ($toRemove as $backupPath) {
            if (file_exists($backupPath)) {
                unlink($backupPath);
            }
        }

        // Update tracking
        $fileKey = $this->getFileKey($filePath);
        $this->fileBackups[$fileKey] = array_slice($backups, 0, $this->config['maxBackupsPerFile']);
    }

    /**
     * Calculate total disk usage of backups
     */
    private function calculateBackupDiskUsage(): int
    {
        $totalSize = 0;
        
        foreach ($this->fileBackups as $backups) {
            foreach ($backups as $backupPath) {
                if (file_exists($backupPath)) {
                    $size = filesize($backupPath);
                    if ($size !== false) {
                        $totalSize += $size;
                    }
                }
            }
        }

        return $totalSize;
    }

    /**
     * Get a unique key for tracking file backups
     */
    private function getFileKey(string $filePath): string
    {
        return md5(realpath($filePath) ?: $filePath);
    }
}

/**
 * Result of a backup operation
 */
final readonly class BackupResult
{
    public function __construct(
        public bool $success,
        public string $originalPath,
        public ?string $backupPath,
        public int $timestamp,
        public int $size,
        public bool $skipped,
        public ?string $error
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
            'originalPath' => $this->originalPath,
            'backupPath' => $this->backupPath,
            'timestamp' => $this->timestamp,
            'size' => $this->size,
            'skipped' => $this->skipped,
            'error' => $this->error,
        ];
    }
}

/**
 * Result of a restore operation
 */
final readonly class RestoreResult
{
    public function __construct(
        public bool $success,
        public string $filePath,
        public ?string $backupPath,
        public ?string $error
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
            'filePath' => $this->filePath,
            'backupPath' => $this->backupPath,
            'error' => $this->error,
        ];
    }
}

/**
 * Result of a cleanup operation
 */
final readonly class CleanupResult
{
    /**
     * @param bool $success Whether the cleanup was successful
     * @param int $filesRemoved Number of files removed
     * @param array<string> $errors Array of error messages
     */
    public function __construct(
        public bool $success,
        public int $filesRemoved,
        public array $errors
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
            'filesRemoved' => $this->filesRemoved,
            'errors' => $this->errors,
        ];
    }
}
