<?php

declare(strict_types=1);

use PestPluginBrowserRecording\Config\RecordingConfig;

it('provides default configuration', function () {
    $defaults = RecordingConfig::defaults();
    
    expect($defaults)
        ->toBeArray()
        ->toHaveKey('timeout')
        ->toHaveKey('autoAssertions')
        ->toHaveKey('selectorPriority');
});

it('merges user options with defaults', function () {
    $merged = RecordingConfig::merge(['timeout' => 300]);
    
    expect($merged['timeout'])->toBe(300);
    expect($merged['autoAssertions'])->toBe(true); // default value preserved
});

it('validates timeout option', function () {
    expect(fn() => RecordingConfig::validate(['timeout' => -1]))
        ->toThrow(InvalidArgumentException::class);
        
    expect(fn() => RecordingConfig::validate(['timeout' => 'invalid']))
        ->toThrow(InvalidArgumentException::class);
});

it('validates boolean options', function () {
    expect(fn() => RecordingConfig::validate(['autoAssertions' => 'not-a-boolean']))
        ->toThrow(InvalidArgumentException::class);
        
    // Valid boolean should not throw
    RecordingConfig::validate(['backupFiles' => true]);
    expect(true)->toBeTrue(); // If we get here, no exception was thrown
});

it('validates selector priority option', function () {
    expect(fn() => RecordingConfig::validate(['selectorPriority' => 'not-an-array']))
        ->toThrow(InvalidArgumentException::class);
        
    // Valid array should not throw
    RecordingConfig::validate(['selectorPriority' => ['data-testid', 'id']]);
    expect(true)->toBeTrue(); // If we get here, no exception was thrown
});
