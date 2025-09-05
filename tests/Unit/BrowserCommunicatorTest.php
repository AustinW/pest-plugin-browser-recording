<?php

declare(strict_types=1);

use PestPluginBrowserRecording\Communication\BrowserCommunicator;

it('creates browser communicator with action handler', function () {
    $handlerCalled = false;
    $handler = function () use (&$handlerCalled) {
        $handlerCalled = true;
    };
    
    $communicator = new BrowserCommunicator($handler);
    expect($communicator)->toBeInstanceOf(BrowserCommunicator::class);
});

it('can set action handler after construction', function () {
    $communicator = new BrowserCommunicator();
    
    $handler = function ($type, $data) {
        expect($type)->toBe('test');
        expect($data)->toBe(['foo' => 'bar']);
    };
    
    $communicator->setActionHandler($handler);
    
    // Simulate processing an action
    $reflection = new ReflectionClass($communicator);
    $method = $reflection->getMethod('validateAndProcessActions');
    $method->setAccessible(true);
    
    $actions = [
        [
            'type' => 'test',
            'data' => ['foo' => 'bar'],
            'timestamp' => time()
        ]
    ];
    
    $result = $method->invoke($communicator, $actions);
    expect($result)->toHaveCount(1);
});

it('validates and sanitizes action data correctly', function () {
    $communicator = new BrowserCommunicator();
    
    $reflection = new ReflectionClass($communicator);
    $method = $reflection->getMethod('validateAndProcessActions');
    $method->setAccessible(true);
    
    // Test valid action
    $validActions = [
        [
            'type' => 'click',
            'data' => ['selector' => '#button'],
            'timestamp' => 1234567890,
            'sessionId' => 'test-session',
            'url' => 'https://example.com'
        ]
    ];
    
    $result = $method->invoke($communicator, $validActions);
    expect($result)->toHaveCount(1);
    expect($result[0]['type'])->toBe('click');
    expect($result[0]['data']['selector'])->toBe('#button');
});

it('filters out invalid actions', function () {
    $communicator = new BrowserCommunicator();
    
    $reflection = new ReflectionClass($communicator);
    $method = $reflection->getMethod('validateAndProcessActions');
    $method->setAccessible(true);
    
    // Test invalid actions
    $invalidActions = [
        'not-an-array',
        [],
        ['type' => 123], // Invalid type
        ['data' => 'not-array'], // Invalid data
        ['type' => 'valid', 'data' => ['test' => 'value']] // Valid - should pass
    ];
    
    $result = $method->invoke($communicator, $invalidActions);
    expect($result)->toHaveCount(1); // Only the valid one should pass
    expect($result[0]['type'])->toBe('valid');
});

it('sanitizes string values to prevent injection', function () {
    $communicator = new BrowserCommunicator();
    
    $reflection = new ReflectionClass($communicator);
    $method = $reflection->getMethod('sanitizeString');
    $method->setAccessible(true);
    
    // Test with control characters and null bytes
    $maliciousString = "test\x00\x01\x1F\x7Fvalue";
    $sanitized = $method->invoke($communicator, $maliciousString);
    
    expect($sanitized)->toBe('testvalue');
});

it('limits string length to prevent memory issues', function () {
    $communicator = new BrowserCommunicator();
    
    $reflection = new ReflectionClass($communicator);
    $method = $reflection->getMethod('sanitizeString');
    $method->setAccessible(true);
    
    // Test with very long string
    $longString = str_repeat('a', 20000);
    $sanitized = $method->invoke($communicator, $longString);
    
    expect(strlen($sanitized))->toBe(10000); // Should be limited to 10000 chars
});

it('handles nested action data properly', function () {
    $communicator = new BrowserCommunicator();
    
    $reflection = new ReflectionClass($communicator);
    $method = $reflection->getMethod('sanitizeActionData');
    $method->setAccessible(true);
    
    $nestedData = [
        'simple' => 'value',
        'number' => 42,
        'boolean' => true,
        'nested' => [
            'level2' => 'value2',
            'number2' => 99
        ],
        'null_value' => null,
        'object' => (object)['prop' => 'value'] // Should be filtered out
    ];
    
    $result = $method->invoke($communicator, $nestedData);
    
    expect($result['simple'])->toBe('value');
    expect($result['number'])->toBe(42);
    expect($result['boolean'])->toBeTrue();
    expect($result['nested']['level2'])->toBe('value2');
    expect($result['null_value'])->toBeNull();
    expect($result['object'])->toBeNull(); // Objects should be converted to null
});

it('tracks statistics correctly', function () {
    $communicator = new BrowserCommunicator();
    
    $stats = $communicator->getStats();
    expect($stats['actionsProcessed'])->toBe(0);
    expect($stats['errors'])->toBe(0);
    expect($stats['lastPollTime'])->toBeNull();
    
    // Reset stats should work
    $communicator->resetStats();
    $stats = $communicator->getStats();
    expect($stats['actionsProcessed'])->toBe(0);
});

it('generates proper initialization script', function () {
    $communicator = new BrowserCommunicator();
    
    $reflection = new ReflectionClass($communicator);
    $method = $reflection->getMethod('buildInitializationScript');
    $method->setAccessible(true);
    
    $script = $method->invoke($communicator, 'test-session-123');
    
    expect($script)->toContain('__pestRecordingActions');
    expect($script)->toContain('__pestRecordingSession');
    expect($script)->toContain('test-session-123');
    expect($script)->toContain('__pestHeartbeat');
    expect($script)->toContain('__pestCommunicationError');
});
