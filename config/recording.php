<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Default Recording Configuration
    |--------------------------------------------------------------------------
    |
    | These are the default configuration values for browser recording sessions.
    | These can be overridden on a per-test basis when calling record().
    |
    */

    'timeout' => 1800, // 30 minutes
    'autoAssertions' => true,
    'selectorPriority' => ['data-testid', 'id', 'name', 'class'],
    'generateComments' => true,
    'includeHoverActions' => false,
    'captureKeyboardShortcuts' => false,
    'recordScrollPosition' => false,

    /*
    |--------------------------------------------------------------------------
    | File Safety and Backup Configuration
    |--------------------------------------------------------------------------
    |
    | Configure backup behavior for test file modifications. Backups are
    | disabled by default for a smooth development experience, but can be
    | enabled for extra safety when needed.
    |
    */

    'backupFiles' => false, // Disabled by default for better developer experience
    'backupDirectory' => '.pest-recording-backups',
    'maxBackupsPerFile' => 10,
    'autoCleanupBackups' => true,

    /*
    |--------------------------------------------------------------------------
    | Supported Actions
    |--------------------------------------------------------------------------
    |
    | List of browser actions that can be recorded and their priority levels.
    |
    */

    'supportedActions' => [
        'click' => ['priority' => 'P0', 'method' => 'click'],
        'fill' => ['priority' => 'P0', 'method' => 'fill'],
        'select' => ['priority' => 'P0', 'method' => 'select'],
        'check' => ['priority' => 'P0', 'method' => 'check'],
        'navigate' => ['priority' => 'P0', 'method' => 'navigate'],
        'assertSee' => ['priority' => 'P1', 'method' => 'assertSee'],
        'attach' => ['priority' => 'P2', 'method' => 'attach'],
        'drag' => ['priority' => 'P2', 'method' => 'drag'],
    ],
];
