<?php

declare(strict_types=1);

use PestPluginBrowserRecording\Recorder\RecordingSession;

it('creates recording session with page instance and config', function () {
    $mockPage = new stdClass();
    $config = ['timeout' => 300];
    
    $session = new RecordingSession($mockPage, $config);
    
    expect($session->getConfig())->toBe($config);
});

it('handles recorded actions', function () {
    $mockPage = new stdClass();
    $session = new RecordingSession($mockPage);
    
    $session->handleAction('click', ['selector' => '#button']);
    $actions = $session->getRecordedActions();
    
    expect($actions)
        ->toHaveCount(1)
        ->and($actions[0])
        ->toHaveKey('type', 'click')
        ->toHaveKey('data')
        ->toHaveKey('timestamp')
        ->toHaveKey('sessionId');
});

it('starts recording session without errors', function () {
    $mockPage = new stdClass();
    $session = new RecordingSession($mockPage);
    
    // Should not throw an exception
    $session->start();
    expect(true)->toBeTrue(); // If we get here, no exception was thrown
});

it('validates configuration on construction', function () {
    $mockPage = new stdClass();
    
    expect(fn() => new RecordingSession($mockPage, ['timeout' => -1]))
        ->toThrow(InvalidArgumentException::class);
});
