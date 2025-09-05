<?php

declare(strict_types=1);

use PestPluginBrowserRecording\Recorder\RecordingSession;

it('handles session initialization with null page gracefully', function () {
    $mockPage = new stdClass();
    $session = new RecordingSession($mockPage);
    
    // Should not throw when page is not a Playwright page
    $session->start();
    expect($session->getRecordedActions())->toBeArray()->toBeEmpty();
});

it('stores session configuration correctly', function () {
    $config = [
        'timeout' => 600,
        'autoAssertions' => false,
        'selectorPriority' => ['data-testid', 'id']
    ];
    
    $mockPage = new stdClass();
    $session = new RecordingSession($mockPage, $config);
    
    expect($session->getConfig())->toBe($config);
});

it('records multiple actions in sequence', function () {
    $mockPage = new stdClass();
    $session = new RecordingSession($mockPage);
    
    $session->handleAction('click', ['selector' => '#button1']);
    $session->handleAction('fill', ['selector' => '#input1', 'value' => 'test']);
    $session->handleAction('click', ['selector' => '#submit']);
    
    $actions = $session->getRecordedActions();
    expect($actions)->toHaveCount(3);
    
    expect($actions[0]['type'])->toBe('click');
    expect($actions[1]['type'])->toBe('fill');
    expect($actions[2]['type'])->toBe('click');
});

it('includes session metadata in recorded actions', function () {
    $mockPage = new stdClass();
    $session = new RecordingSession($mockPage);
    
    $session->handleAction('click', ['selector' => '#test']);
    $actions = $session->getRecordedActions();
    
    expect($actions[0])
        ->toHaveKey('sessionId')
        ->toHaveKey('timestamp')
        ->toHaveKey('type')
        ->toHaveKey('data');
});

it('handles empty action data gracefully', function () {
    $mockPage = new stdClass();
    $session = new RecordingSession($mockPage);
    
    $session->handleAction('navigate', []);
    $actions = $session->getRecordedActions();
    
    expect($actions)->toHaveCount(1);
    expect($actions[0]['data'])->toBe([]);
});
