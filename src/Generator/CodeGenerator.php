<?php

declare(strict_types=1);

namespace PestPluginBrowserRecording\Generator;

use PhpParser\Builder\Method;
use PhpParser\Builder\Namespace_;
use PhpParser\Builder\Use_;
use PhpParser\BuilderFactory;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PhpParser\Node\Arg;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Expression;
use PhpParser\PrettyPrinter\Standard;
use PestPluginBrowserRecording\Recorder\ActionData;
use InvalidArgumentException;

/**
 * Generates idiomatic Pest v4 browser test code from recorded actions
 * 
 * This class transforms recorded browser actions into clean, maintainable
 * Pest test code using AST manipulation for guaranteed syntax correctness.
 */
final class CodeGenerator
{
    /**
     * Mapping of recorded action types to Pest browser methods
     */
    private const ACTION_MAPPING = [
        // Navigation actions
        'session:start' => null, // Special handling - creates visit() call
        'navigation' => 'visit',
        'beforeunload' => null, // No direct Pest equivalent
        
        // Interaction actions
        'click' => 'click',
        'dblclick' => 'doubleClick',
        'rightclick' => 'rightClick',
        'input' => 'fill',
        'change' => 'select', // Context-dependent
        'focus' => null, // Usually implicit in Pest
        'blur' => null, // Usually implicit in Pest
        'submit' => 'press',
        'keydown' => 'keys',
        
        // Scroll and hover actions
        'scroll' => 'scrollTo',
        'hover' => 'hover',
        
        // Session management
        'session:end' => null, // No direct equivalent
        'session:heartbeat' => null, // Internal only
        
        // DOM changes (for assertions)
        'dom:added' => null, // Can generate assertions
        'visibility' => null, // Can generate assertions
        
        // Error handling
        'communication:error' => null, // Internal only
    ];

    /**
     * Input types that should use type() instead of fill()
     */
    private const TYPE_INSTEAD_OF_FILL = [
        'search', 'url', 'tel', 'email' // Types that benefit from typing simulation
    ];

    /**
     * @var BuilderFactory AST builder factory
     */
    private BuilderFactory $factory;

    /**
     * @var Standard Pretty printer for code output
     */
    private Standard $printer;

    /**
     * @var array<string, mixed> Generation configuration
     */
    private array $config;

    /**
     * @var SelectorStrategy Selector generation strategy
     */
    private SelectorStrategy $selectorStrategy;

    public function __construct(
        array $config = [],
        ?SelectorStrategy $selectorStrategy = null
    ) {
        $this->factory = new BuilderFactory();
        $this->printer = new Standard();
        $this->selectorStrategy = $selectorStrategy ?? new SelectorStrategy();
        
        $this->config = array_merge([
            'autoAssertions' => true,
            'includeComments' => true,
            'chainMethods' => true,
            'testName' => 'recorded browser test',
            'useTypeForInputs' => true,
            'generateWaits' => false,
            'deviceEmulation' => null, // 'mobile', 'desktop', null
            'colorScheme' => null, // 'dark', 'light', null
        ], $config);
    }

    /**
     * Generate a complete Pest test from recorded actions
     * 
     * @param array<ActionData> $actions Recorded actions in chronological order
     * @param string $testName Optional test name override
     * @return CodeGenerationResult Generated test code with metadata
     */
    public function generateTest(array $actions, string $testName = null): CodeGenerationResult
    {
        if (empty($actions)) {
            throw new InvalidArgumentException('Cannot generate test from empty actions array');
        }

        $testName = $testName ?? $this->config['testName'];
        $pestStatements = $this->convertActionsToPestCalls($actions);
        
        // Build the test function
        $testMethod = $this->buildTestMethod($testName, $pestStatements);
        
        // Create the complete PHP file
        $namespace = $this->factory->namespace('Tests\\Feature')
            ->addStmt($testMethod);

        $code = $this->printer->prettyPrintFile([$namespace->getNode()]);
        
        return new CodeGenerationResult(
            code: $code,
            testName: $testName,
            actionCount: count($actions),
            pestMethodCount: count($pestStatements),
            hasAssertions: $this->hasAssertions($pestStatements),
            usedSelectors: $this->extractUsedSelectors($pestStatements)
        );
    }

    /**
     * Generate individual Pest method calls from actions
     * 
     * @param array<ActionData> $actions
     * @return array<CodeStatement> Generated code statements
     */
    public function convertActionsToPestCalls(array $actions): array
    {
        $statements = [];
        $pageVariable = new Variable('page');
        $currentUrl = null;

        foreach ($actions as $action) {
            $statement = $this->convertSingleAction($action, $pageVariable, $currentUrl);
            
            if ($statement !== null) {
                $statements[] = $statement;
                
                // Track URL changes for context
                if ($action->type === 'session:start' || $action->type === 'navigation') {
                    $currentUrl = $action->url;
                }
            }
        }

        // Add automatic assertions if enabled
        if ($this->config['autoAssertions']) {
            $statements = array_merge($statements, $this->generateAutoAssertions($actions));
        }

        return $statements;
    }

    /**
     * Convert a single action to a Pest method call
     */
    private function convertSingleAction(ActionData $action, Variable $pageVar, ?string $currentUrl): ?CodeStatement
    {
        $pestMethod = self::ACTION_MAPPING[$action->type] ?? null;
        
        // Handle special cases
        switch ($action->type) {
            case 'session:start':
                return $this->generateVisitCall($action, $pageVar);
                
            case 'click':
                return $this->generateClickCall($action, $pageVar);
                
            case 'input':
                return $this->generateInputCall($action, $pageVar);
                
            case 'change':
                return $this->generateChangeCall($action, $pageVar);
                
            case 'submit':
                return $this->generateSubmitCall($action, $pageVar);
                
            case 'keydown':
                return $this->generateKeyCall($action, $pageVar);
                
            case 'scroll':
                return $this->generateScrollCall($action, $pageVar);
                
            case 'hover':
                return $this->generateHoverCall($action, $pageVar);
                
            case 'navigation':
                if ($action->data['type'] === 'popstate' || 
                    $action->data['type'] === 'pushstate' || 
                    $action->data['type'] === 'replacestate') {
                    return $this->generateNavigationCall($action, $pageVar);
                }
                break;
                
            default:
                // Skip unsupported actions
                return null;
        }

        return null;
    }

    /**
     * Generate visit() call for session start
     */
    private function generateVisitCall(ActionData $action, Variable $pageVar): CodeStatement
    {
        $url = $action->url ?: '/';
        $args = [new String_($url)];
        
        // Add device emulation if configured
        $methodCalls = [];
        if ($this->config['deviceEmulation']) {
            $methodCalls[] = $this->factory->methodCall(
                new Variable('visit'),
                $this->config['deviceEmulation']
            );
        }
        
        // Add color scheme if configured
        if ($this->config['colorScheme']) {
            $methodName = $this->config['colorScheme'] === 'dark' ? 'inDarkMode' : 'inLightMode';
            $methodCalls[] = $this->factory->methodCall(
                new Variable('visit'),
                $methodName
            );
        }

        $visitCall = $this->factory->funcCall('visit', $args);
        
        // Chain additional method calls if any
        if (!empty($methodCalls)) {
            foreach ($methodCalls as $methodCall) {
                $visitCall = $this->factory->methodCall($visitCall, $methodCall->name);
            }
        }

        $comment = $this->config['includeComments'] 
            ? "Visit {$url}" 
            : null;

        return new CodeStatement(
            expression: new Assign($pageVar, $visitCall),
            comment: $comment,
            type: 'navigation'
        );
    }

    /**
     * Generate click() call
     */
    private function generateClickCall(ActionData $action, Variable $pageVar): CodeStatement
    {
        $selector = $this->extractSelector($action);
        $args = [new String_($selector)];

        $methodCall = $this->factory->methodCall($pageVar, 'click', $args);
        
        $comment = $this->config['includeComments'] 
            ? "Click on {$selector}" 
            : null;

        return new CodeStatement(
            expression: $methodCall,
            comment: $comment,
            type: 'interaction'
        );
    }

    /**
     * Generate fill() or type() call for inputs
     */
    private function generateInputCall(ActionData $action, Variable $pageVar): CodeStatement
    {
        $selector = $this->extractSelector($action);
        $value = (string)($action->data['value'] ?? '');
        $inputType = (string)($action->data['inputType'] ?? 'text');
        
        // Decide between fill() and type()
        $useType = $this->config['useTypeForInputs'] && 
                   in_array($inputType, self::TYPE_INSTEAD_OF_FILL);
        
        $method = $useType ? 'type' : 'fill';
        $args = [new String_($selector), new String_($value)];

        $methodCall = $this->factory->methodCall($pageVar, $method, $args);
        
        $comment = $this->config['includeComments'] 
            ? "{$method} '{$value}' in {$selector}" 
            : null;

        return new CodeStatement(
            expression: $methodCall,
            comment: $comment,
            type: 'interaction'
        );
    }

    /**
     * Generate select() call for dropdowns
     */
    private function generateChangeCall(ActionData $action, Variable $pageVar): ?CodeStatement
    {
        // Only handle select elements
        if (($action->data['tagName'] ?? '') !== 'select') {
            return null;
        }

        $selector = $this->extractSelector($action);
        $value = (string)($action->data['value'] ?? '');
        
        $args = [new String_($selector), new String_($value)];
        $methodCall = $this->factory->methodCall($pageVar, 'select', $args);
        
        $comment = $this->config['includeComments'] 
            ? "Select '{$value}' in {$selector}" 
            : null;

        return new CodeStatement(
            expression: $methodCall,
            comment: $comment,
            type: 'interaction'
        );
    }

    /**
     * Generate press() call for form submission
     */
    private function generateSubmitCall(ActionData $action, Variable $pageVar): CodeStatement
    {
        $selector = $this->extractSelector($action);
        $args = [new String_($selector)];

        $methodCall = $this->factory->methodCall($pageVar, 'press', $args);
        
        $comment = $this->config['includeComments'] 
            ? "Submit form via {$selector}" 
            : null;

        return new CodeStatement(
            expression: $methodCall,
            comment: $comment,
            type: 'interaction'
        );
    }

    /**
     * Generate keys() call for keyboard shortcuts
     */
    private function generateKeyCall(ActionData $action, Variable $pageVar): ?CodeStatement
    {
        $key = (string)($action->data['key'] ?? '');
        $modifiers = $action->data['modifiers'] ?? [];
        
        if (empty($key)) {
            return null;
        }

        // Build key combination string
        $keyCombo = [];
        if ($modifiers['ctrl'] ?? false) $keyCombo[] = 'Control';
        if ($modifiers['shift'] ?? false) $keyCombo[] = 'Shift';
        if ($modifiers['alt'] ?? false) $keyCombo[] = 'Alt';
        if ($modifiers['meta'] ?? false) $keyCombo[] = 'Meta';
        $keyCombo[] = $key;

        $keyString = implode('+', $keyCombo);
        $args = [new String_($keyString)];

        $methodCall = $this->factory->methodCall($pageVar, 'keys', $args);
        
        $comment = $this->config['includeComments'] 
            ? "Press {$keyString}" 
            : null;

        return new CodeStatement(
            expression: $methodCall,
            comment: $comment,
            type: 'interaction'
        );
    }

    /**
     * Generate scrollTo() call
     */
    private function generateScrollCall(ActionData $action, Variable $pageVar): ?CodeStatement
    {
        if (!$this->config['generateWaits']) {
            return null; // Skip scroll actions unless explicitly enabled
        }

        $x = (int)($action->data['scrollX'] ?? 0);
        $y = (int)($action->data['scrollY'] ?? 0);
        
        $args = [
            $this->factory->val($x),
            $this->factory->val($y)
        ];

        $methodCall = $this->factory->methodCall($pageVar, 'scrollTo', $args);
        
        $comment = $this->config['includeComments'] 
            ? "Scroll to ({$x}, {$y})" 
            : null;

        return new CodeStatement(
            expression: $methodCall,
            comment: $comment,
            type: 'interaction'
        );
    }

    /**
     * Generate hover() call
     */
    private function generateHoverCall(ActionData $action, Variable $pageVar): CodeStatement
    {
        $selector = $this->extractSelector($action);
        $args = [new String_($selector)];

        $methodCall = $this->factory->methodCall($pageVar, 'hover', $args);
        
        $comment = $this->config['includeComments'] 
            ? "Hover over {$selector}" 
            : null;

        return new CodeStatement(
            expression: $methodCall,
            comment: $comment,
            type: 'interaction'
        );
    }

    /**
     * Generate visit() call for navigation
     */
    private function generateNavigationCall(ActionData $action, Variable $pageVar): CodeStatement
    {
        $url = $action->data['url'] ?? $action->url ?? '/';
        $args = [new String_($url)];

        $methodCall = $this->factory->methodCall($pageVar, 'visit', $args);
        
        $comment = $this->config['includeComments'] 
            ? "Navigate to {$url}" 
            : null;

        return new CodeStatement(
            expression: $methodCall,
            comment: $comment,
            type: 'navigation'
        );
    }

    /**
     * Extract selector from action data
     */
    private function extractSelector(ActionData $action): string
    {
        // If action already has a selector, use it
        if (isset($action->data['selector']) && !empty($action->data['selector'])) {
            return (string)$action->data['selector'];
        }

        // Otherwise, try to generate one using the SelectorStrategy
        $elementData = [
            'tagName' => $action->data['tagName'] ?? 'div',
            'attributes' => $action->data['attributes'] ?? []
        ];

        try {
            $result = $this->selectorStrategy->generateSelector($elementData);
            return $result->selector;
        } catch (\Exception $e) {
            // Fallback to a simple selector
            return $action->data['tagName'] ?? 'body';
        }
    }

    /**
     * Generate automatic assertions based on recorded actions
     * 
     * @param array<ActionData> $actions
     * @return array<CodeStatement>
     */
    private function generateAutoAssertions(array $actions): array
    {
        $assertions = [];
        $pageVar = new Variable('page');

        // Look for final URL to assert
        $finalUrl = null;
        foreach (array_reverse($actions) as $action) {
            if (!empty($action->url) && $action->type !== 'session:start') {
                $finalUrl = $action->url;
                break;
            }
        }

        if ($finalUrl && $finalUrl !== '/') {
            $assertions[] = new CodeStatement(
                expression: $this->factory->methodCall(
                    $pageVar, 
                    'assertUrlContains', 
                    [new String_($finalUrl)]
                ),
                comment: $this->config['includeComments'] ? 'Assert final URL' : null,
                type: 'assertion'
            );
        }

        // Assert no JavaScript errors
        $assertions[] = new CodeStatement(
            expression: $this->factory->methodCall($pageVar, 'assertNoJavascriptErrors'),
            comment: $this->config['includeComments'] ? 'Ensure no JavaScript errors' : null,
            type: 'assertion'
        );

        return $assertions;
    }

    /**
     * Build the complete test method
     * 
     * @param string $testName
     * @param array<CodeStatement> $statements
     * @return Expression
     */
    private function buildTestMethod(string $testName, array $statements): Expression
    {
        $stmts = [];

        // Add statements to the closure
        foreach ($statements as $statement) {
            if ($statement->comment) {
                // Comments will be handled by the pretty printer if we add them as attributes
                // For now, we'll skip inline comments in the generated code
            }
            
            // Wrap expression in Expression statement if needed
            if ($statement->expression instanceof Expression) {
                $stmts[] = $statement->expression;
            } else {
                $stmts[] = new Expression($statement->expression);
            }
        }

        // Create anonymous closure
        $closure = new Closure([
            'stmts' => $stmts
        ]);

        // Create the it() call
        $itCall = new FuncCall(
            new Name('it'),
            [
                new Arg(new String_($testName)),
                new Arg($closure)
            ]
        );
        
        return new Expression($itCall);
    }

    /**
     * Check if statements contain assertions
     * 
     * @param array<CodeStatement> $statements
     */
    private function hasAssertions(array $statements): bool
    {
        foreach ($statements as $statement) {
            if ($statement->type === 'assertion') {
                return true;
            }
        }
        return false;
    }

    /**
     * Extract selectors used in statements
     * 
     * @param array<CodeStatement> $statements
     * @return array<string>
     */
    private function extractUsedSelectors(array $statements): array
    {
        $selectors = [];
        
        foreach ($statements as $statement) {
            // This would need more sophisticated AST traversal to extract selectors
            // For now, we'll return an empty array as a placeholder
        }
        
        return $selectors;
    }
}

/**
 * Represents a single code statement with metadata
 */
final readonly class CodeStatement
{
    public function __construct(
        public Node\Stmt|Node\Expr $expression,
        public ?string $comment,
        public string $type
    ) {
    }
}

/**
 * Result of code generation with metadata
 */
final readonly class CodeGenerationResult
{
    public function __construct(
        public string $code,
        public string $testName,
        public int $actionCount,
        public int $pestMethodCount,
        public bool $hasAssertions,
        public array $usedSelectors
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
            'code' => $this->code,
            'testName' => $this->testName,
            'actionCount' => $this->actionCount,
            'pestMethodCount' => $this->pestMethodCount,
            'hasAssertions' => $this->hasAssertions,
            'usedSelectors' => $this->usedSelectors,
        ];
    }
}
