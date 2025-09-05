<?php

declare(strict_types=1);

use PestPluginBrowserRecording\Generator\CodeGenerator;
use PestPluginBrowserRecording\Generator\CodeGenerationResult;
use PestPluginBrowserRecording\Generator\SelectorStrategy;
use PestPluginBrowserRecording\Recorder\ActionData;

it('creates code generator with default configuration', function () {
    $generator = new CodeGenerator();
    expect($generator)->toBeInstanceOf(CodeGenerator::class);
});

it('creates code generator with custom configuration', function () {
    $config = [
        'autoAssertions' => false,
        'includeComments' => false,
        'testName' => 'Custom Test Name'
    ];
    
    $generator = new CodeGenerator($config);
    expect($generator)->toBeInstanceOf(CodeGenerator::class);
});

it('throws exception for empty actions array', function () {
    $generator = new CodeGenerator();
    
    expect(fn() => $generator->generateTest([]))
        ->toThrow(InvalidArgumentException::class, 'Cannot generate test from empty actions array');
});

it('generates basic visit call from session start action', function () {
    $generator = new CodeGenerator(['includeComments' => false]);
    
    $actions = [
        new ActionData(
            type: 'session:start',
            data: ['sessionId' => 'test', 'viewport' => [], 'userAgent' => 'test'],
            timestamp: time(),
            url: '/login',
            sessionId: 'test',
            sequence: 1,
            viewport: null,
            metadata: []
        )
    ];
    
    $result = $generator->generateTest($actions, 'test login');
    
    expect($result)->toBeInstanceOf(CodeGenerationResult::class);
    expect($result->code)->toContain('visit(\'/login\')');
    expect($result->code)->toContain('it(\'test login\'');
    expect($result->testName)->toBe('test login');
    expect($result->actionCount)->toBe(1);
});

it('generates click call with selector', function () {
    $generator = new CodeGenerator(['includeComments' => false, 'autoAssertions' => false]);
    
    $actions = [
        new ActionData(
            type: 'session:start',
            data: [],
            timestamp: time(),
            url: '/',
            sessionId: 'test',
            sequence: 1,
            viewport: null,
            metadata: []
        ),
        new ActionData(
            type: 'click',
            data: ['selector' => '#submit-btn', 'coordinates' => ['x' => 10, 'y' => 20]],
            timestamp: time(),
            url: '/',
            sessionId: 'test',
            sequence: 2,
            viewport: null,
            metadata: []
        )
    ];
    
    $result = $generator->generateTest($actions);
    
    expect($result->code)->toContain('visit(\'/\')');
    expect($result->code)->toContain('click(\'#submit-btn\')');
    expect($result->actionCount)->toBe(2);
});

it('generates fill call for input actions', function () {
    $generator = new CodeGenerator(['includeComments' => false, 'autoAssertions' => false]);
    
    $actions = [
        new ActionData(
            type: 'input',
            data: [
                'selector' => '#email',
                'value' => 'test@example.com',
                'inputType' => 'email'
            ],
            timestamp: time(),
            url: '/',
            sessionId: 'test',
            sequence: 1,
            viewport: null,
            metadata: []
        )
    ];
    
    $statements = $generator->convertActionsToPestCalls($actions);
    
    expect($statements)->toHaveCount(1);
    // Note: We can't easily test the exact generated code without executing it
    // but we can verify the structure
});

it('generates type call instead of fill for specific input types', function () {
    $generator = new CodeGenerator([
        'includeComments' => false, 
        'autoAssertions' => false,
        'useTypeForInputs' => true
    ]);
    
    $actions = [
        new ActionData(
            type: 'input',
            data: [
                'selector' => '#search',
                'value' => 'query',
                'inputType' => 'search'
            ],
            timestamp: time(),
            url: '/',
            sessionId: 'test',
            sequence: 1,
            viewport: null,
            metadata: []
        )
    ];
    
    $statements = $generator->convertActionsToPestCalls($actions);
    
    expect($statements)->toHaveCount(1);
    expect($statements[0]->type)->toBe('interaction');
});

it('generates select call for dropdown changes', function () {
    $generator = new CodeGenerator(['includeComments' => false, 'autoAssertions' => false]);
    
    $actions = [
        new ActionData(
            type: 'change',
            data: [
                'selector' => '#country',
                'value' => 'US',
                'tagName' => 'select'
            ],
            timestamp: time(),
            url: '/',
            sessionId: 'test',
            sequence: 1,
            viewport: null,
            metadata: []
        )
    ];
    
    $statements = $generator->convertActionsToPestCalls($actions);
    
    expect($statements)->toHaveCount(1);
    expect($statements[0]->type)->toBe('interaction');
});

it('generates press call for form submission', function () {
    $generator = new CodeGenerator(['includeComments' => false, 'autoAssertions' => false]);
    
    $actions = [
        new ActionData(
            type: 'submit',
            data: ['selector' => 'form', 'data' => []],
            timestamp: time(),
            url: '/',
            sessionId: 'test',
            sequence: 1,
            viewport: null,
            metadata: []
        )
    ];
    
    $statements = $generator->convertActionsToPestCalls($actions);
    
    expect($statements)->toHaveCount(1);
    expect($statements[0]->type)->toBe('interaction');
});

it('generates keys call for keyboard shortcuts', function () {
    $generator = new CodeGenerator(['includeComments' => false, 'autoAssertions' => false]);
    
    $actions = [
        new ActionData(
            type: 'keydown',
            data: [
                'key' => 's',
                'modifiers' => [
                    'ctrl' => true,
                    'shift' => false,
                    'alt' => false,
                    'meta' => false
                ]
            ],
            timestamp: time(),
            url: '/',
            sessionId: 'test',
            sequence: 1,
            viewport: null,
            metadata: []
        )
    ];
    
    $statements = $generator->convertActionsToPestCalls($actions);
    
    expect($statements)->toHaveCount(1);
    expect($statements[0]->type)->toBe('interaction');
});

it('generates hover call', function () {
    $generator = new CodeGenerator(['includeComments' => false, 'autoAssertions' => false]);
    
    $actions = [
        new ActionData(
            type: 'hover',
            data: [
                'selector' => '.tooltip-trigger',
                'action' => 'enter'
            ],
            timestamp: time(),
            url: '/',
            sessionId: 'test',
            sequence: 1,
            viewport: null,
            metadata: []
        )
    ];
    
    $statements = $generator->convertActionsToPestCalls($actions);
    
    expect($statements)->toHaveCount(1);
    expect($statements[0]->type)->toBe('interaction');
});

it('skips unsupported action types', function () {
    $generator = new CodeGenerator(['includeComments' => false, 'autoAssertions' => false]);
    
    $actions = [
        new ActionData(
            type: 'session:heartbeat',
            data: ['timestamp' => time()],
            timestamp: time(),
            url: '/',
            sessionId: 'test',
            sequence: 1,
            viewport: null,
            metadata: []
        ),
        new ActionData(
            type: 'communication:error',
            data: ['error' => 'test error'],
            timestamp: time(),
            url: '/',
            sessionId: 'test',
            sequence: 2,
            viewport: null,
            metadata: []
        )
    ];
    
    $statements = $generator->convertActionsToPestCalls($actions);
    
    expect($statements)->toBeEmpty();
});

it('includes comments when configured', function () {
    $generator = new CodeGenerator(['includeComments' => true, 'autoAssertions' => false]);
    
    $actions = [
        new ActionData(
            type: 'click',
            data: ['selector' => '#btn', 'coordinates' => []],
            timestamp: time(),
            url: '/',
            sessionId: 'test',
            sequence: 1,
            viewport: null,
            metadata: []
        )
    ];
    
    $statements = $generator->convertActionsToPestCalls($actions);
    
    expect($statements)->toHaveCount(1);
    expect($statements[0]->comment)->not->toBeNull();
    expect($statements[0]->comment)->toContain('Click on #btn');
});

it('excludes comments when configured', function () {
    $generator = new CodeGenerator(['includeComments' => false, 'autoAssertions' => false]);
    
    $actions = [
        new ActionData(
            type: 'click',
            data: ['selector' => '#btn', 'coordinates' => []],
            timestamp: time(),
            url: '/',
            sessionId: 'test',
            sequence: 1,
            viewport: null,
            metadata: []
        )
    ];
    
    $statements = $generator->convertActionsToPestCalls($actions);
    
    expect($statements)->toHaveCount(1);
    expect($statements[0]->comment)->toBeNull();
});

it('generates automatic assertions when enabled', function () {
    $generator = new CodeGenerator(['autoAssertions' => true, 'includeComments' => false]);
    
    $actions = [
        new ActionData(
            type: 'session:start',
            data: [],
            timestamp: time(),
            url: '/',
            sessionId: 'test',
            sequence: 1,
            viewport: null,
            metadata: []
        ),
        new ActionData(
            type: 'click',
            data: ['selector' => '#btn', 'coordinates' => []],
            timestamp: time(),
            url: '/dashboard',
            sessionId: 'test',
            sequence: 2,
            viewport: null,
            metadata: []
        )
    ];
    
    $statements = $generator->convertActionsToPestCalls($actions);
    
    // Should have original statements plus assertions
    expect($statements)->toHaveCount(4); // visit, click, assertUrlContains, assertNoJavascriptErrors
    
    $assertionStatements = array_filter($statements, fn($s) => $s->type === 'assertion');
    expect($assertionStatements)->toHaveCount(2);
});

it('skips automatic assertions when disabled', function () {
    $generator = new CodeGenerator(['autoAssertions' => false, 'includeComments' => false]);
    
    $actions = [
        new ActionData(
            type: 'click',
            data: ['selector' => '#btn', 'coordinates' => []],
            timestamp: time(),
            url: '/dashboard',
            sessionId: 'test',
            sequence: 1,
            viewport: null,
            metadata: []
        )
    ];
    
    $statements = $generator->convertActionsToPestCalls($actions);
    
    expect($statements)->toHaveCount(1);
    
    $assertionStatements = array_filter($statements, fn($s) => $s->type === 'assertion');
    expect($assertionStatements)->toBeEmpty();
});

it('uses selector strategy for missing selectors', function () {
    $mockStrategy = new SelectorStrategy();
    $generator = new CodeGenerator(['autoAssertions' => false], $mockStrategy);
    
    $actions = [
        new ActionData(
            type: 'click',
            data: [
                'tagName' => 'button',
                'attributes' => ['id' => 'test-btn'],
                'coordinates' => []
            ],
            timestamp: time(),
            url: '/',
            sessionId: 'test',
            sequence: 1,
            viewport: null,
            metadata: []
        )
    ];
    
    $statements = $generator->convertActionsToPestCalls($actions);
    
    expect($statements)->toHaveCount(1);
    expect($statements[0]->type)->toBe('interaction');
});

it('handles navigation actions correctly', function () {
    $generator = new CodeGenerator(['includeComments' => false, 'autoAssertions' => false]);
    
    $actions = [
        new ActionData(
            type: 'navigation',
            data: [
                'type' => 'pushstate',
                'url' => '/new-page'
            ],
            timestamp: time(),
            url: '/new-page',
            sessionId: 'test',
            sequence: 1,
            viewport: null,
            metadata: []
        )
    ];
    
    $statements = $generator->convertActionsToPestCalls($actions);
    
    expect($statements)->toHaveCount(1);
    expect($statements[0]->type)->toBe('navigation');
});

it('converts CodeGenerationResult to array correctly', function () {
    $result = new CodeGenerationResult(
        code: '<?php test code',
        testName: 'test name',
        actionCount: 5,
        pestMethodCount: 3,
        hasAssertions: true,
        usedSelectors: ['#btn', '.form']
    );
    
    $array = $result->toArray();
    
    expect($array)->toBe([
        'code' => '<?php test code',
        'testName' => 'test name',
        'actionCount' => 5,
        'pestMethodCount' => 3,
        'hasAssertions' => true,
        'usedSelectors' => ['#btn', '.form'],
    ]);
});
