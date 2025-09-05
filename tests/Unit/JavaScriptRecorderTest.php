<?php

declare(strict_types=1);

it('recorder javascript file exists and is readable', function () {
    $recorderPath = __DIR__ . '/../../resources/js/recorder.js';
    expect(file_exists($recorderPath))->toBeTrue();
    expect(is_readable($recorderPath))->toBeTrue();
});

it('recorder javascript contains required classes and methods', function () {
    $recorderPath = __DIR__ . '/../../resources/js/recorder.js';
    $content = file_get_contents($recorderPath);
    
    // Check for main class
    expect($content)->toContain('class PestRecorder');
    expect($content)->toContain('class SelectorGenerator');
    
    // Check for essential methods
    expect($content)->toContain('start()');
    expect($content)->toContain('stop()');
    expect($content)->toContain('recordAction');
    expect($content)->toContain('generate(element)');
    
    // Check for event handlers
    expect($content)->toContain('handleClick');
    expect($content)->toContain('handleInput');
    expect($content)->toContain('handleSubmit');
    expect($content)->toContain('attachEventListeners');
});

it('recorder javascript has proper ES6+ syntax and structure', function () {
    $recorderPath = __DIR__ . '/../../resources/js/recorder.js';
    $content = file_get_contents($recorderPath);
    
    // Check for ES6+ features
    expect($content)->toContain('constructor(');
    expect($content)->toContain('const ');
    expect($content)->toContain('let ');
    expect($content)->toContain('=>');
    
    // Check for modern APIs
    expect($content)->toContain('MutationObserver');
    expect($content)->toContain('IntersectionObserver');
    expect($content)->toContain('addEventListener');
});

it('recorder javascript includes security and privacy features', function () {
    $recorderPath = __DIR__ . '/../../resources/js/recorder.js';
    $content = file_get_contents($recorderPath);
    
    // Check for sanitization
    expect($content)->toContain('sanitizeValue');
    expect($content)->toContain('shouldIgnoreElement');
    
    // Check for password masking
    expect($content)->toContain('password');
    expect($content)->toContain('*\'.repeat');
});

it('recorder javascript includes performance optimizations', function () {
    $recorderPath = __DIR__ . '/../../resources/js/recorder.js';
    $content = file_get_contents($recorderPath);
    
    // Check for throttling and debouncing
    expect($content)->toContain('throttle');
    expect($content)->toContain('debounce');
    expect($content)->toContain('scrollThrottle');
    expect($content)->toContain('inputDebounce');
});

it('recorder javascript has comprehensive selector generation', function () {
    $recorderPath = __DIR__ . '/../../resources/js/recorder.js';
    $content = file_get_contents($recorderPath);
    
    // Check for selector priority
    expect($content)->toContain('data-testid');
    expect($content)->toContain('tryAttribute');
    expect($content)->toContain('tryClass');
    expect($content)->toContain('generateCssPath');
    expect($content)->toContain('isUnique');
});

it('recorder javascript includes cross-browser compatibility features', function () {
    $recorderPath = __DIR__ . '/../../resources/js/recorder.js';
    $content = file_get_contents($recorderPath);
    
    // Check for browser compatibility measures
    expect($content)->toContain('CSS.escape');
    expect($content)->toContain('escapeSelector'); // Fallback
    expect($content)->toContain('typeof CSS');
});

it('recorder javascript has proper cleanup mechanisms', function () {
    $recorderPath = __DIR__ . '/../../resources/js/recorder.js';
    $content = file_get_contents($recorderPath);
    
    // Check for cleanup
    expect($content)->toContain('cleanup()');
    expect($content)->toContain('removeEventListener');
    expect($content)->toContain('disconnect()');
    expect($content)->toContain('eventListeners.clear()');
});
