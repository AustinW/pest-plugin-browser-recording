<?php

declare(strict_types=1);

use PestPluginBrowserRecording\Generator\SelectorStrategy;
use PestPluginBrowserRecording\Generator\SelectorResult;
use PestPluginBrowserRecording\Generator\SelectorValidationResult;

it('creates selector strategy with default configuration', function () {
    $strategy = new SelectorStrategy();
    expect($strategy)->toBeInstanceOf(SelectorStrategy::class);
});

it('creates selector strategy with custom configuration', function () {
    $customPriority = ['data-testid', 'id'];
    $strategy = new SelectorStrategy(
        customPriority: $customPriority,
        includeAria: false,
        useDirectDescendants: true,
        maxDepth: 3
    );
    
    expect($strategy)->toBeInstanceOf(SelectorStrategy::class);
});

it('generates selector using data-testid attribute', function () {
    $strategy = new SelectorStrategy();
    
    $elementData = [
        'tagName' => 'button',
        'attributes' => [
            'data-testid' => 'submit-button',
            'id' => 'btn-submit',
            'class' => 'btn btn-primary'
        ]
    ];
    
    $result = $strategy->generateSelector($elementData);
    
    expect($result->selector)->toBe('[data-testid="submit-button"]');
    expect($result->strategy)->toBe('attribute-data-testid');
    expect($result->confidence)->toBe(0.98);
    expect($result->isStable)->toBeTrue();
});

it('falls back to id when data-testid is not available', function () {
    $strategy = new SelectorStrategy();
    
    $elementData = [
        'tagName' => 'button',
        'attributes' => [
            'id' => 'submit-btn',
            'class' => 'btn btn-primary'
        ]
    ];
    
    $result = $strategy->generateSelector($elementData);
    
    expect($result->selector)->toBe('#submit-btn');
    expect($result->strategy)->toBe('attribute-id');
    expect($result->confidence)->toBe(0.95);
    expect($result->isStable)->toBeTrue();
});

it('falls back to name attribute for form elements', function () {
    $strategy = new SelectorStrategy();
    
    $elementData = [
        'tagName' => 'input',
        'attributes' => [
            'name' => 'email',
            'type' => 'email',
            'class' => 'form-control'
        ]
    ];
    
    $result = $strategy->generateSelector($elementData);
    
    expect($result->selector)->toBe('[name="email"]');
    expect($result->strategy)->toBe('attribute-name');
    expect($result->confidence)->toBe(0.9);
    expect($result->isStable)->toBeTrue();
});

it('generates class-based selector when no priority attributes exist', function () {
    $strategy = new SelectorStrategy();
    
    $elementData = [
        'tagName' => 'div',
        'attributes' => [
            'class' => 'card header navigation'
        ]
    ];
    
    $result = $strategy->generateSelector($elementData);
    
    expect($result->selector)->toBe('div.card.header.navigation');
    expect($result->strategy)->toBe('class-based');
    expect($result->confidence)->toBe(0.7);
    expect($result->isStable)->toBeFalse();
});

it('ignores unreliable CSS classes', function () {
    $strategy = new SelectorStrategy();
    
    $elementData = [
        'tagName' => 'div',
        'attributes' => [
            'class' => '_generated123 css-abc123 stable-class'
        ]
    ];
    
    $result = $strategy->generateSelector($elementData);
    
    // Should only use the stable class
    expect($result->selector)->toBe('div.stable-class');
    expect($result->strategy)->toBe('class-based');
});

it('generates tag with attributes selector for form elements', function () {
    $strategy = new SelectorStrategy();
    
    $elementData = [
        'tagName' => 'input',
        'attributes' => [
            'type' => 'submit',
            'value' => 'Send Message',
            'class' => '_generated123456 css-abc123' // Truly unreliable classes
        ]
    ];
    
    $result = $strategy->generateSelector($elementData);
    
    expect($result->selector)->toContain('input');
    expect($result->selector)->toContain('type="submit"');
    expect($result->selector)->toContain('value="Send Message"');
    expect($result->strategy)->toBe('tag-with-attributes');
    expect($result->confidence)->toBe(0.8);
    expect($result->isStable)->toBeTrue();
});

it('includes ARIA attributes when enabled', function () {
    $strategy = new SelectorStrategy(includeAria: true);
    
    $elementData = [
        'tagName' => 'button',
        'attributes' => [
            'aria-label' => 'Close dialog',
            'aria-expanded' => 'false',
            'class' => '_generated123456 css-unreliable' // Truly unreliable classes
        ]
    ];
    
    $result = $strategy->generateSelector($elementData);
    
    expect($result->selector)->toContain('aria-label="Close dialog"');
    expect($result->strategy)->toBe('tag-with-attributes');
});

it('excludes ARIA attributes when disabled', function () {
    $strategy = new SelectorStrategy(includeAria: false);
    
    $elementData = [
        'tagName' => 'button',
        'attributes' => [
            'aria-label' => 'Close dialog',
            'class' => 'btn-close'
        ]
    ];
    
    $result = $strategy->generateSelector($elementData);
    
    expect($result->selector)->toBe('button.btn-close');
    expect($result->strategy)->toBe('class-based');
});

it('generates hierarchical selector as fallback', function () {
    $strategy = new SelectorStrategy();
    
    $elementData = [
        'tagName' => 'span',
        'attributes' => [],
        'path' => [
            ['tagName' => 'div', 'attributes' => ['id' => 'main']],
            ['tagName' => 'section', 'attributes' => ['class' => 'content']],
            ['tagName' => 'p', 'attributes' => [], 'nthChild' => 2]
        ]
    ];
    
    $result = $strategy->generateSelector($elementData);
    
    expect($result->selector)->toContain('#main');
    expect($result->strategy)->toBe('hierarchical');
    expect($result->confidence)->toBe(0.6);
    expect($result->isStable)->toBeFalse();
});

it('validates selector uniqueness correctly', function () {
    $strategy = new SelectorStrategy();
    
    $html = '
        <div>
            <button id="unique-btn">Click me</button>
            <button class="duplicate">Button 1</button>
            <button class="duplicate">Button 2</button>
        </div>
    ';
    
    // Test unique selector
    $uniqueResult = $strategy->validateSelector('#unique-btn', $html);
    expect($uniqueResult->isValid)->toBeTrue();
    expect($uniqueResult->isUnique)->toBeTrue();
    expect($uniqueResult->matchCount)->toBe(1);
    expect($uniqueResult->error)->toBeNull();
    
    // Test non-unique selector
    $duplicateResult = $strategy->validateSelector('.duplicate', $html);
    expect($duplicateResult->isValid)->toBeTrue();
    expect($duplicateResult->isUnique)->toBeFalse();
    expect($duplicateResult->matchCount)->toBe(2);
    
    // Test invalid selector
    $invalidResult = $strategy->validateSelector('#nonexistent', $html);
    expect($invalidResult->isValid)->toBeFalse();
    expect($invalidResult->isUnique)->toBeFalse();
    expect($invalidResult->matchCount)->toBe(0);
});

it('validates selector stability across multiple DOM samples', function () {
    $strategy = new SelectorStrategy();
    
    $html1 = '<div><button id="test-btn">Click</button></div>';
    $html2 = '<div><p>Text</p><button id="test-btn">Click</button></div>';
    $html3 = '<div><button id="other-btn">Click</button></div>';
    
    $results = $strategy->validateSelectorStability('#test-btn', [$html1, $html2, $html3]);
    
    expect($results)->toHaveCount(3);
    expect($results['sample_0']->isValid)->toBeTrue();
    expect($results['sample_1']->isValid)->toBeTrue();
    expect($results['sample_2']->isValid)->toBeFalse(); // Not present in this sample
});

it('generates multiple selector candidates with scores', function () {
    $strategy = new SelectorStrategy();
    
    $elementData = [
        'tagName' => 'button',
        'attributes' => [
            'data-testid' => 'submit',
            'id' => 'btn-submit',
            'name' => 'submit-btn',
            'class' => 'btn btn-primary',
            'type' => 'submit'
        ]
    ];
    
    $candidates = $strategy->generateSelectorCandidates($elementData);
    
    expect($candidates)->toHaveCount(6); // data-testid, data-cy, data-test, id, name, class-based, tag-with-attributes
    
    // Should be sorted by confidence (highest first)
    expect($candidates[0]->confidence)->toBeGreaterThan($candidates[1]->confidence);
    expect($candidates[0]->strategy)->toBe('attribute-data-testid');
    expect($candidates[0]->confidence)->toBe(0.98);
});

it('escapes special characters in attribute values', function () {
    $strategy = new SelectorStrategy();
    
    $elementData = [
        'tagName' => 'input',
        'attributes' => [
            'data-testid' => 'input-with-"quotes"-and-backslash\\',
            'placeholder' => "Enter value with 'quotes'"
        ]
    ];
    
    $result = $strategy->generateSelector($elementData);
    
    // Should properly escape quotes and backslashes
    expect($result->selector)->toContain('data-testid="input-with-\\"quotes\\"-and-backslash\\\\"');
});

it('escapes special characters in CSS class names', function () {
    $strategy = new SelectorStrategy();
    
    $elementData = [
        'tagName' => 'div',
        'attributes' => [
            'class' => 'class-with:special.chars[and]others!'
        ]
    ];
    
    $result = $strategy->generateSelector($elementData);
    
    // Should escape special CSS characters
    expect($result->selector)->toContain('class-with\\:special\\.chars\\[and\\]others\\!');
});

it('throws exception for missing tagName', function () {
    $strategy = new SelectorStrategy();
    
    $elementData = [
        'attributes' => ['id' => 'test']
    ];
    
    expect(fn() => $strategy->generateSelector($elementData))
        ->toThrow(InvalidArgumentException::class, 'Element data must include tagName');
});

it('handles empty attributes gracefully', function () {
    $strategy = new SelectorStrategy();
    
    $elementData = [
        'tagName' => 'div',
        'attributes' => []
    ];
    
    $result = $strategy->generateSelector($elementData);
    
    // Should fall back to hierarchical or tag selector
    expect($result->selector)->toBe('div');
    expect($result->strategy)->toBe('tag-fallback');
});

it('respects custom attribute priority', function () {
    $customPriority = ['id', 'data-testid', 'name'];
    $strategy = new SelectorStrategy(customPriority: $customPriority);
    
    $elementData = [
        'tagName' => 'input',
        'attributes' => [
            'data-testid' => 'email-input',
            'id' => 'email',
            'name' => 'user_email'
        ]
    ];
    
    $result = $strategy->generateSelector($elementData);
    
    // Should use id first due to custom priority
    expect($result->selector)->toBe('#email');
    expect($result->strategy)->toBe('attribute-id');
});

it('converts SelectorResult to array correctly', function () {
    $result = new SelectorResult(
        selector: '#test-btn',
        strategy: 'attribute-id',
        confidence: 0.95,
        isStable: true
    );
    
    $array = $result->toArray();
    
    expect($array)->toBe([
        'selector' => '#test-btn',
        'strategy' => 'attribute-id',
        'confidence' => 0.95,
        'isStable' => true,
    ]);
});

it('converts SelectorValidationResult to array correctly', function () {
    $result = new SelectorValidationResult(
        isValid: true,
        isUnique: false,
        matchCount: 3,
        error: null
    );
    
    $array = $result->toArray();
    
    expect($array)->toBe([
        'isValid' => true,
        'isUnique' => false,
        'matchCount' => 3,
        'error' => null,
    ]);
});
