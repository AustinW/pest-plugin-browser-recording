<?php

declare(strict_types=1);

use PestPluginBrowserRecording\Recorder\ActionRecorder;
use PestPluginBrowserRecording\Recorder\ActionData;
use PestPluginBrowserRecording\Recorder\ActionMetadata;

it('creates action recorder and initializes empty', function () {
    $recorder = new ActionRecorder();
    
    expect($recorder->getActiveSessions())->toBeEmpty();
    expect($recorder->getStatistics()['totalSessions'])->toBe(0);
    expect($recorder->getStatistics()['totalActions'])->toBe(0);
});

it('records basic click action correctly', function () {
    $recorder = new ActionRecorder();
    $sessionId = 'test-session-123';
    
    $recorder->recordAction($sessionId, 'click', [
        'selector' => '#submit-button',
        'coordinates' => ['x' => 100, 'y' => 200]
    ]);
    
    $actions = $recorder->getSessionActions($sessionId);
    expect($actions)->toHaveCount(1);
    
    $action = $actions[0];
    expect($action->type)->toBe('click');
    expect($action->data['selector'])->toBe('#submit-button');
    expect($action->data['coordinates'])->toBe(['x' => 100, 'y' => 200]);
    expect($action->sessionId)->toBe($sessionId);
    expect($action->sequence)->toBe(1);
});

it('validates required fields for different action types', function () {
    $recorder = new ActionRecorder();
    $sessionId = 'test-session';
    
    // Valid click action
    $recorder->recordAction($sessionId, 'click', [
        'selector' => '#button',
        'coordinates' => ['x' => 10, 'y' => 20]
    ]);
    
    // Invalid click action - missing coordinates
    expect(fn() => $recorder->recordAction($sessionId, 'click', [
        'selector' => '#button'
    ]))->toThrow(InvalidArgumentException::class, "Missing required field 'coordinates'");
    
    // Invalid action type
    expect(fn() => $recorder->recordAction($sessionId, 'invalid-action', []))
        ->toThrow(InvalidArgumentException::class, 'Unsupported action type: invalid-action');
});

it('maintains action sequence correctly', function () {
    $recorder = new ActionRecorder();
    $sessionId = 'sequence-test';
    
    $recorder->recordAction($sessionId, 'click', ['selector' => '#btn1', 'coordinates' => []]);
    $recorder->recordAction($sessionId, 'click', ['selector' => '#btn2', 'coordinates' => []]);
    $recorder->recordAction($sessionId, 'input', ['selector' => '#field', 'value' => 'test', 'inputType' => 'text']);
    
    $actions = $recorder->getSessionActions($sessionId);
    expect($actions)->toHaveCount(3);
    expect($actions[0]->sequence)->toBe(1);
    expect($actions[1]->sequence)->toBe(2);
    expect($actions[2]->sequence)->toBe(3);
});

it('handles multiple sessions independently', function () {
    $recorder = new ActionRecorder();
    
    $recorder->recordAction('session1', 'click', ['selector' => '#btn1', 'coordinates' => []]);
    $recorder->recordAction('session2', 'click', ['selector' => '#btn2', 'coordinates' => []]);
    $recorder->recordAction('session1', 'input', ['selector' => '#input', 'value' => 'test', 'inputType' => 'text']);
    
    $session1Actions = $recorder->getSessionActions('session1');
    $session2Actions = $recorder->getSessionActions('session2');
    
    expect($session1Actions)->toHaveCount(2);
    expect($session2Actions)->toHaveCount(1);
    expect($session1Actions[0]->data['selector'])->toBe('#btn1');
    expect($session2Actions[0]->data['selector'])->toBe('#btn2');
});

it('filters actions by type correctly', function () {
    $recorder = new ActionRecorder();
    $sessionId = 'filter-test';
    
    $recorder->recordAction($sessionId, 'click', ['selector' => '#btn', 'coordinates' => []]);
    $recorder->recordAction($sessionId, 'input', ['selector' => '#field', 'value' => 'test', 'inputType' => 'text']);
    $recorder->recordAction($sessionId, 'click', ['selector' => '#btn2', 'coordinates' => []]);
    $recorder->recordAction($sessionId, 'submit', ['selector' => '#form', 'data' => []]);
    
    $clickActions = $recorder->getActionsByTypes($sessionId, ['click']);
    $formActions = $recorder->getActionsByTypes($sessionId, ['input', 'submit']);
    
    expect($clickActions)->toHaveCount(2);
    expect($formActions)->toHaveCount(2);
    expect($clickActions[0]->type)->toBe('click');
    expect($clickActions[1]->type)->toBe('click');
});

it('filters actions by time range correctly', function () {
    $recorder = new ActionRecorder();
    $sessionId = 'time-test';
    
    $baseTime = 1640995200; // 2022-01-01 00:00:00
    
    $recorder->recordAction($sessionId, 'click', ['selector' => '#btn1', 'coordinates' => []], [
        'timestamp' => $baseTime
    ]);
    $recorder->recordAction($sessionId, 'click', ['selector' => '#btn2', 'coordinates' => []], [
        'timestamp' => $baseTime + 100
    ]);
    $recorder->recordAction($sessionId, 'click', ['selector' => '#btn3', 'coordinates' => []], [
        'timestamp' => $baseTime + 200
    ]);
    
    $midActions = $recorder->getActionsInRange($sessionId, $baseTime + 50, $baseTime + 150);
    expect($midActions)->toHaveCount(1);
    expect($midActions[0]->data['selector'])->toBe('#btn2');
});

it('tracks session metadata correctly', function () {
    $recorder = new ActionRecorder();
    $sessionId = 'metadata-test';
    
    $recorder->recordAction($sessionId, 'session:start', [
        'sessionId' => $sessionId,
        'viewport' => ['width' => 1920, 'height' => 1080],
        'userAgent' => 'Mozilla/5.0 Test Browser'
    ], [
        'url' => 'https://example.com',
        'viewport' => ['width' => 1920, 'height' => 1080]
    ]);
    
    $metadata = $recorder->getSessionMetadata($sessionId);
    expect($metadata)->not->toBeNull();
    expect($metadata->sessionId)->toBe($sessionId);
    expect($metadata->url)->toBe('https://example.com');
    expect($metadata->userAgent)->toBe('Mozilla/5.0 Test Browser');
    expect($metadata->viewport)->toBe(['width' => 1920, 'height' => 1080]);
});

it('exports and imports session data correctly', function () {
    $recorder = new ActionRecorder();
    $sessionId = 'export-test';
    
    $recorder->recordAction($sessionId, 'click', ['selector' => '#btn', 'coordinates' => []]);
    $recorder->recordAction($sessionId, 'input', ['selector' => '#field', 'value' => 'test', 'inputType' => 'text']);
    
    $exportedData = $recorder->exportSession($sessionId);
    
    expect($exportedData['sessionId'])->toBe($sessionId);
    expect($exportedData['totalActions'])->toBe(2);
    expect($exportedData['actions'])->toHaveCount(2);
    expect($exportedData['actions'][0]['type'])->toBe('click');
    
    // Test JSON export
    $jsonData = $recorder->exportSessionToJson($sessionId);
    expect($jsonData)->toBeString();
    $decodedData = json_decode($jsonData, true);
    expect($decodedData['sessionId'])->toBe($sessionId);
    
    // Test import
    $newRecorder = new ActionRecorder();
    $newRecorder->importSession($exportedData);
    
    $importedActions = $newRecorder->getSessionActions($sessionId);
    expect($importedActions)->toHaveCount(2);
    expect($importedActions[0]->type)->toBe('click');
    expect($importedActions[1]->type)->toBe('input');
});

it('sanitizes action data properly', function () {
    $recorder = new ActionRecorder();
    $sessionId = 'sanitize-test';
    
    $maliciousData = [
        'selector' => '#test',
        'coordinates' => [],
        'malicious_script' => '<script>alert("xss")</script>',
        'very_long_string' => str_repeat('A', 20000), // 20k chars
        123 => 'numeric_key', // Should be filtered out
        'null_value' => null,
        'object_value' => (object)['prop' => 'value'], // Should be filtered out
    ];
    
    $recorder->recordAction($sessionId, 'click', $maliciousData);
    
    $actions = $recorder->getSessionActions($sessionId);
    $actionData = $actions[0]->data;
    
    expect($actionData['selector'])->toBe('#test');
    expect($actionData['malicious_script'])->toBe('<script>alert("xss")</script>'); // String preserved as-is
    expect(strlen($actionData['very_long_string']))->toBe(10000); // Truncated
    expect($actionData)->not->toHaveKey('123'); // Numeric key filtered
    expect($actionData)->not->toHaveKey('object_value'); // Object filtered
});

it('enforces session action limits', function () {
    $recorder = new ActionRecorder();
    $sessionId = 'limit-test';
    
    // Should work fine initially
    $recorder->recordAction($sessionId, 'click', ['selector' => '#btn', 'coordinates' => []]);
    
    // Mock the session to have maximum actions by adding to the internal array
    $reflection = new ReflectionClass($recorder);
    $sessionActionsProperty = $reflection->getProperty('sessionActions');
    $sessionActionsProperty->setAccessible(true);
    
    $sessionActions = $sessionActionsProperty->getValue($recorder);
    $sessionActions[$sessionId] = array_fill(0, 10000, new ActionData(
        'click', [], time(), '', $sessionId, 1, null, []
    ));
    $sessionActionsProperty->setValue($recorder, $sessionActions);
    
    // Now it should throw an exception
    expect(fn() => $recorder->recordAction($sessionId, 'click', ['selector' => '#btn2', 'coordinates' => []]))
        ->toThrow(InvalidArgumentException::class, 'has reached maximum action limit');
});

it('provides accurate statistics', function () {
    $recorder = new ActionRecorder();
    
    $recorder->recordAction('session1', 'click', ['selector' => '#btn1', 'coordinates' => []]);
    $recorder->recordAction('session1', 'click', ['selector' => '#btn2', 'coordinates' => []]);
    $recorder->recordAction('session2', 'input', ['selector' => '#field', 'value' => 'test', 'inputType' => 'text']);
    
    $stats = $recorder->getStatistics();
    
    expect($stats['totalSessions'])->toBe(2);
    expect($stats['totalActions'])->toBe(3);
    expect($stats['averageActionsPerSession'])->toBe(1.5);
    expect($stats['largestSession'])->toBe(2);
    expect($stats['smallestSession'])->toBe(1);
    expect($stats['activeSessions'])->toBe(['session1', 'session2']);
});

it('clears sessions correctly', function () {
    $recorder = new ActionRecorder();
    
    $recorder->recordAction('session1', 'click', ['selector' => '#btn1', 'coordinates' => []]);
    $recorder->recordAction('session2', 'click', ['selector' => '#btn2', 'coordinates' => []]);
    
    expect($recorder->getActiveSessions())->toHaveCount(2);
    
    $recorder->clearSession('session1');
    expect($recorder->getActiveSessions())->toBe(['session2']);
    expect($recorder->getSessionActions('session1'))->toBeEmpty();
    
    $recorder->clearAll();
    expect($recorder->getActiveSessions())->toBeEmpty();
    expect($recorder->getStatistics()['totalActions'])->toBe(0);
});

it('creates ActionData from array correctly', function () {
    $data = [
        'type' => 'click',
        'data' => ['selector' => '#btn'],
        'timestamp' => 1640995200,
        'sessionId' => 'test',
        'sequence' => 1,
        'url' => 'https://example.com',
        'viewport' => ['width' => 1920],
        'metadata' => ['test' => 'value']
    ];
    
    $actionData = ActionData::fromArray($data);
    
    expect($actionData->type)->toBe('click');
    expect($actionData->data)->toBe(['selector' => '#btn']);
    expect($actionData->timestamp)->toBe(1640995200);
    expect($actionData->sessionId)->toBe('test');
    expect($actionData->sequence)->toBe(1);
    expect($actionData->url)->toBe('https://example.com');
    expect($actionData->viewport)->toBe(['width' => 1920]);
    expect($actionData->metadata)->toBe(['test' => 'value']);
});

it('creates ActionMetadata from array correctly', function () {
    $data = [
        'sessionId' => 'test-session',
        'startTime' => 1640995200,
        'lastActionTime' => 1640995300,
        'userAgent' => 'Test Browser',
        'viewport' => ['width' => 1920, 'height' => 1080],
        'url' => 'https://example.com'
    ];
    
    $metadata = ActionMetadata::fromArray($data);
    
    expect($metadata->sessionId)->toBe('test-session');
    expect($metadata->startTime)->toBe(1640995200);
    expect($metadata->lastActionTime)->toBe(1640995300);
    expect($metadata->userAgent)->toBe('Test Browser');
    expect($metadata->viewport)->toBe(['width' => 1920, 'height' => 1080]);
    expect($metadata->url)->toBe('https://example.com');
});
