<?php

declare(strict_types=1);

namespace PestPluginBrowserRecording\Generator;

use DOMDocument;
use DOMElement;
use DOMXPath;
use InvalidArgumentException;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\CssSelector\CssSelectorConverter;

/**
 * Smart selector generation strategy for stable, unique element identification
 * 
 * This class implements intelligent selector generation that prioritizes stable
 * attributes (data-testid, id, name) and falls back to robust CSS selectors
 * when needed. It ensures generated selectors are unique and maintainable.
 */
final class SelectorStrategy
{
    /**
     * Attribute priority order for selector generation
     */
    private const ATTRIBUTE_PRIORITY = [
        'data-testid',
        'data-cy',        // Cypress convention
        'data-test',      // Common test attribute
        'id',
        'name',
        'data-qa',        // QA attribute
        'role',           // ARIA role
    ];

    /**
     * CSS classes to avoid in selectors (typically dynamic or unreliable)
     */
    private const UNRELIABLE_CLASS_PATTERNS = [
        '/^_/',           // CSS modules (starts with underscore)
        '/\d{6,}/',       // Long numeric sequences (likely generated)
        '/^css-/',        // CSS-in-JS libraries
        '/^makeStyles/',  // Material-UI generated classes
        '/random/',       // Classes with "random" in name
        '/hash/',         // Classes with "hash" in name
        '/temp/',         // Temporary classes
        '/^jss\d+/',      // JSS generated classes
    ];

    /**
     * Tags that are significant for interaction and should be prioritized
     */
    private const INTERACTIVE_TAGS = [
        'button', 'input', 'select', 'textarea', 'a', 'form',
        'label', 'option', 'optgroup', 'fieldset', 'legend'
    ];

    /**
     * @var array<string> Custom selector priority configuration
     */
    private array $customPriority;

    /**
     * @var bool Whether to include ARIA attributes in selector generation
     */
    private bool $includeAria;

    /**
     * @var bool Whether to use descendant combinators (>) vs descendant selectors ( )
     */
    private bool $useDirectDescendants;

    /**
     * @var int Maximum depth for CSS selector generation
     */
    private int $maxDepth;

    public function __construct(
        array $customPriority = [],
        bool $includeAria = true,
        bool $useDirectDescendants = false,
        int $maxDepth = 5
    ) {
        $this->customPriority = $customPriority ?: self::ATTRIBUTE_PRIORITY;
        $this->includeAria = $includeAria;
        $this->useDirectDescendants = $useDirectDescendants;
        $this->maxDepth = $maxDepth;
    }

    /**
     * Generate a selector for the given element data
     * 
     * @param array<string, mixed> $elementData Element information from the browser
     * @return SelectorResult Selector result with strategy used
     */
    public function generateSelector(array $elementData): SelectorResult
    {
        // Validate input data
        if (!isset($elementData['tagName'])) {
            throw new InvalidArgumentException('Element data must include tagName');
        }

        $tagName = strtolower((string)$elementData['tagName']);
        $attributes = $elementData['attributes'] ?? [];
        
        // Try priority attributes first
        $attributeResult = $this->tryAttributeSelectors($attributes);
        if ($attributeResult !== null) {
            return $attributeResult;
        }

        // Try tag with unique attributes (higher priority than classes)
        $tagResult = $this->tryTagWithAttributes($tagName, $attributes);
        if ($tagResult !== null) {
            return $tagResult;
        }

        // Try class-based selector
        $classResult = $this->tryClassSelector($tagName, $attributes);
        if ($classResult !== null) {
            return $classResult;
        }

        // Fallback to hierarchical selector
        return $this->generateHierarchicalSelector($elementData);
    }

    /**
     * Validate that a selector would uniquely match an element in the given DOM
     * 
     * @param string $selector CSS selector to validate
     * @param string $htmlContent HTML content to validate against
     * @return SelectorValidationResult Validation result
     */
    public function validateSelector(string $selector, string $htmlContent): SelectorValidationResult
    {
        try {
            $crawler = new Crawler($htmlContent);
            $matches = $crawler->filter($selector);
            
            $matchCount = $matches->count();
            $isValid = $matchCount > 0;
            $isUnique = $matchCount === 1;

            return new SelectorValidationResult(
                isValid: $isValid,
                isUnique: $isUnique,
                matchCount: $matchCount,
                error: null
            );

        } catch (\Exception $e) {
            return new SelectorValidationResult(
                isValid: false,
                isUnique: false,
                matchCount: 0,
                error: $e->getMessage()
            );
        }
    }

    /**
     * Validate selector against multiple DOM samples for stability testing
     * 
     * @param string $selector CSS selector to validate
     * @param array<string> $htmlSamples Multiple HTML content samples
     * @return array<SelectorValidationResult> Validation results for each sample
     */
    public function validateSelectorStability(string $selector, array $htmlSamples): array
    {
        $results = [];
        
        foreach ($htmlSamples as $index => $htmlContent) {
            $results["sample_{$index}"] = $this->validateSelector($selector, $htmlContent);
        }
        
        return $results;
    }

    /**
     * Generate selector based on element hierarchy
     */
    private function generateHierarchicalSelector(array $elementData): SelectorResult
    {
        $tagName = strtolower((string)$elementData['tagName']);
        $path = $elementData['path'] ?? [];
        
        if (empty($path)) {
            // Fallback to simple tag selector
            return new SelectorResult(
                selector: $tagName,
                strategy: 'tag-fallback',
                confidence: 0.3,
                isStable: false
            );
        }

        $selectorParts = [];
        $depth = 0;
        
        foreach (array_reverse($path) as $pathElement) {
            if ($depth >= $this->maxDepth) {
                break;
            }
            
            $part = $this->buildPathElement($pathElement);
            if ($part !== null) {
                $selectorParts[] = $part;
                $depth++;
            }
        }

        $combinator = $this->useDirectDescendants ? ' > ' : ' ';
        $selector = implode($combinator, array_reverse($selectorParts));

        return new SelectorResult(
            selector: $selector,
            strategy: 'hierarchical',
            confidence: 0.6,
            isStable: false
        );
    }

    /**
     * Try to generate selector using priority attributes
     */
    private function tryAttributeSelectors(array $attributes): ?SelectorResult
    {
        foreach ($this->customPriority as $attribute) {
            if (isset($attributes[$attribute]) && !empty($attributes[$attribute])) {
                $value = $this->escapeAttributeValue((string)$attributes[$attribute]);
                
                if ($attribute === 'id') {
                    $selector = "#{$value}";
                    $confidence = 0.95;
                } else {
                    $selector = "[{$attribute}=\"{$value}\"]";
                    $confidence = $attribute === 'data-testid' ? 0.98 : 0.9;
                }

                return new SelectorResult(
                    selector: $selector,
                    strategy: "attribute-{$attribute}",
                    confidence: $confidence,
                    isStable: true
                );
            }
        }

        return null;
    }

    /**
     * Try to generate selector using reliable CSS classes
     */
    private function tryClassSelector(string $tagName, array $attributes): ?SelectorResult
    {
        if (!isset($attributes['class']) || empty($attributes['class'])) {
            return null;
        }

        $classes = array_filter(
            explode(' ', (string)$attributes['class']),
            [$this, 'isReliableClass']
        );

        if (empty($classes)) {
            return null;
        }

        // Use up to 3 classes for specificity without being too brittle
        $selectedClasses = array_slice($classes, 0, 3);
        $classSelector = '.' . implode('.', array_map([$this, 'escapeClassName'], $selectedClasses));
        
        // Combine with tag for better specificity
        $selector = $tagName . $classSelector;

        return new SelectorResult(
            selector: $selector,
            strategy: 'class-based',
            confidence: 0.7,
            isStable: false
        );
    }

    /**
     * Try to generate selector using tag with distinguishing attributes
     */
    private function tryTagWithAttributes(string $tagName, array $attributes): ?SelectorResult
    {
        $distinguishingAttributes = [];

        // For form elements, include type
        if (isset($attributes['type']) && in_array($tagName, ['input', 'button'])) {
            $distinguishingAttributes[] = 'type="' . $this->escapeAttributeValue((string)$attributes['type']) . '"';
        }

        // Include placeholder for inputs
        if (isset($attributes['placeholder']) && !empty($attributes['placeholder'])) {
            $placeholder = $this->escapeAttributeValue((string)$attributes['placeholder']);
            $distinguishingAttributes[] = "placeholder=\"{$placeholder}\"";
        }

        // Include value for inputs if it's not user-generated
        if (isset($attributes['value']) && $tagName === 'input' && isset($attributes['type'])) {
            $type = (string)$attributes['type'];
            if (in_array($type, ['submit', 'button', 'reset'])) {
                $value = $this->escapeAttributeValue((string)$attributes['value']);
                $distinguishingAttributes[] = "value=\"{$value}\"";
            }
        }

        // Include ARIA attributes if enabled
        if ($this->includeAria) {
            foreach ($attributes as $attr => $value) {
                if (str_starts_with($attr, 'aria-') && !empty($value)) {
                    $distinguishingAttributes[] = $attr . '="' . $this->escapeAttributeValue((string)$value) . '"';
                }
            }
        }

        if (empty($distinguishingAttributes)) {
            return null;
        }

        $selector = $tagName . '[' . implode('][', $distinguishingAttributes) . ']';

        return new SelectorResult(
            selector: $selector,
            strategy: 'tag-with-attributes',
            confidence: 0.8,
            isStable: true
        );
    }

    /**
     * Build a path element for hierarchical selectors
     */
    private function buildPathElement(array $pathElement): ?string
    {
        $tagName = strtolower((string)($pathElement['tagName'] ?? ''));
        
        if (empty($tagName)) {
            return null;
        }

        // Try to use stable attributes first
        $attributes = $pathElement['attributes'] ?? [];
        
        if (isset($attributes['id']) && !empty($attributes['id'])) {
            return "#{$this->escapeAttributeValue((string)$attributes['id'])}";
        }

        if (isset($attributes['data-testid']) && !empty($attributes['data-testid'])) {
            $value = $this->escapeAttributeValue((string)$attributes['data-testid']);
            return "[data-testid=\"{$value}\"]";
        }

        // Use nth-child if available
        if (isset($pathElement['nthChild']) && $pathElement['nthChild'] > 1) {
            return "{$tagName}:nth-child({$pathElement['nthChild']})";
        }

        return $tagName;
    }

    /**
     * Check if a CSS class is reliable for selector generation
     */
    private function isReliableClass(string $className): bool
    {
        if (empty($className)) {
            return false;
        }

        foreach (self::UNRELIABLE_CLASS_PATTERNS as $pattern) {
            if (preg_match($pattern, $className)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Escape CSS attribute value
     */
    private function escapeAttributeValue(string $value): string
    {
        // Escape quotes and backslashes
        return addcslashes($value, '"\\');
    }

    /**
     * Escape CSS class name
     */
    private function escapeClassName(string $className): string
    {
        // Escape special CSS characters
        return preg_replace('/([!"#$%&\'()*+,.\/:;<=>?@[\\\\\]^`{|}~])/', '\\\\$1', $className) ?? $className;
    }

    /**
     * Generate multiple selector candidates and score them
     * 
     * @param array<string, mixed> $elementData Element information
     * @return array<SelectorResult> Array of selector candidates with scores
     */
    public function generateSelectorCandidates(array $elementData): array
    {
        $candidates = [];
        
        $tagName = strtolower((string)$elementData['tagName']);
        $attributes = $elementData['attributes'] ?? [];
        
        // Try all priority attributes
        foreach ($this->customPriority as $attribute) {
            if (isset($attributes[$attribute]) && !empty($attributes[$attribute])) {
                $value = $this->escapeAttributeValue((string)$attributes[$attribute]);
                
                if ($attribute === 'id') {
                    $selector = "#{$value}";
                    $confidence = 0.95;
                } else {
                    $selector = "[{$attribute}=\"{$value}\"]";
                    $confidence = $attribute === 'data-testid' ? 0.98 : 0.9;
                }

                $candidates[] = new SelectorResult(
                    selector: $selector,
                    strategy: "attribute-{$attribute}",
                    confidence: $confidence,
                    isStable: true
                );
            }
        }
        
        // Try class-based selector
        $classResult = $this->tryClassSelector($tagName, $attributes);
        if ($classResult !== null) {
            $candidates[] = $classResult;
        }
        
        // Try tag with attributes
        $tagResult = $this->tryTagWithAttributes($tagName, $attributes);
        if ($tagResult !== null) {
            $candidates[] = $tagResult;
        }
        
        // Add hierarchical selector
        $candidates[] = $this->generateHierarchicalSelector($elementData);
        
        // Sort by confidence score (highest first)
        usort($candidates, fn($a, $b) => $b->confidence <=> $a->confidence);
        
        return $candidates;
    }
}

/**
 * Result of selector generation with metadata
 */
final readonly class SelectorResult
{
    public function __construct(
        public string $selector,
        public string $strategy,
        public float $confidence,
        public bool $isStable
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
            'selector' => $this->selector,
            'strategy' => $this->strategy,
            'confidence' => $this->confidence,
            'isStable' => $this->isStable,
        ];
    }
}

/**
 * Result of selector validation
 */
final readonly class SelectorValidationResult
{
    public function __construct(
        public bool $isValid,
        public bool $isUnique,
        public int $matchCount,
        public ?string $error
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
            'isValid' => $this->isValid,
            'isUnique' => $this->isUnique,
            'matchCount' => $this->matchCount,
            'error' => $this->error,
        ];
    }
}
