<?php

declare(strict_types=1);

namespace PestPluginBrowserRecording\Injector;

use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Stmt;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;
use PestPluginBrowserRecording\Injector\BackupManager;
use InvalidArgumentException;
use RuntimeException;

/**
 * Safely injects generated code into PHP test files using AST manipulation
 * 
 * This class provides safe, reliable code injection by parsing the target file
 * into an AST, locating the ->record() call, and injecting new code immediately
 * after it while preserving formatting and comments.
 */
final class FileInjector
{
    /**
     * @var Parser PHP parser instance
     */
    private Parser $parser;

    /**
     * @var Standard Pretty printer for code output
     */
    private Standard $printer;

    /**
     * @var array<string, mixed> Injection configuration
     */
    private array $config;

    /**
     * @var BackupManager Backup management system
     */
    private BackupManager $backupManager;

    public function __construct(array $config = [])
    {
        $this->parser = (new ParserFactory())->createForNewestSupportedVersion();
        $this->printer = new Standard();
        
        $this->config = array_merge([
            'createBackup' => false, // Off by default for better developer experience
            'backupSuffix' => '.backup',
            'preserveComments' => true,
            'verifyInjection' => true,
            'maxFileSize' => 1024 * 1024, // 1MB limit
        ], $config);
        
        // Initialize backup manager
        $backupConfig = [
            'enabled' => $this->config['createBackup'],
            'maxFileSize' => $this->config['maxFileSize'],
        ];
        $this->backupManager = new BackupManager($backupConfig);
    }

    /**
     * Inject code into a test file after the ->record() call
     * 
     * @param string $filePath Path to the test file
     * @param string $codeToInject PHP code to inject (without <?php tags)
     * @return InjectionResult Result of the injection operation
     */
    public function injectAfterRecordCall(string $filePath, string $codeToInject): InjectionResult
    {
        // Validate input
        if (!file_exists($filePath)) {
            throw new InvalidArgumentException("File does not exist: {$filePath}");
        }

        if (!is_readable($filePath)) {
            throw new InvalidArgumentException("File is not readable: {$filePath}");
        }

        if (!is_writable($filePath)) {
            throw new InvalidArgumentException("File is not writable: {$filePath}");
        }

        // Check file size
        $fileSize = filesize($filePath);
        if ($fileSize === false || $fileSize > $this->config['maxFileSize']) {
            throw new InvalidArgumentException("File is too large or unreadable: {$filePath}");
        }

        // Read and parse the original file
        $originalContent = file_get_contents($filePath);
        if ($originalContent === false) {
            throw new RuntimeException("Failed to read file: {$filePath}");
        }

        // Create backup using BackupManager
        $backupResult = $this->backupManager->createBackup($filePath);
        $backupPath = $backupResult->backupPath;

        try {
            // Parse the file into AST
            $ast = $this->parseFileToAst($originalContent);
            
            // Find the ->record() call location
            $injectionPoint = $this->findRecordCallLocation($ast);
            
            if ($injectionPoint === null) {
                throw new RuntimeException("No ->record() call found in file: {$filePath}");
            }

            // Parse the code to inject
            $codeAst = $this->parseCodeToInject($codeToInject);
            
            // Inject the code
            $modifiedAst = $this->injectCodeAtLocation($ast, $injectionPoint, $codeAst);
            
            // Convert back to PHP code
            $newContent = $this->astToPhpCode($modifiedAst);
            
            // Verify injection if configured
            if ($this->config['verifyInjection']) {
                $this->verifyInjection($newContent, $codeToInject);
            }

            // Write the modified content
            $writeResult = file_put_contents($filePath, $newContent);
            if ($writeResult === false) {
                throw new RuntimeException("Failed to write modified content to file: {$filePath}");
            }

            return new InjectionResult(
                success: true,
                filePath: $filePath,
                backupPath: $backupPath,
                originalSize: strlen($originalContent),
                newSize: strlen($newContent),
                injectionPoint: $injectionPoint,
                error: null
            );

        } catch (\Exception $e) {
            // If backup was created and injection failed, offer to restore
            $errorMessage = "Injection failed: " . $e->getMessage();
            if ($backupPath && file_exists($backupPath)) {
                $errorMessage .= " Backup available at: {$backupPath}";
            }

            return new InjectionResult(
                success: false,
                filePath: $filePath,
                backupPath: $backupPath,
                originalSize: strlen($originalContent),
                newSize: 0,
                injectionPoint: null,
                error: $errorMessage
            );
        }
    }

    /**
     * Restore a file from its backup
     * 
     * @param string $filePath Original file path
     * @param string $backupPath Backup file path
     * @return bool True if restoration was successful
     */
    public function restoreFromBackup(string $filePath, string $backupPath): bool
    {
        if (!file_exists($backupPath)) {
            return false;
        }

        $backupContent = file_get_contents($backupPath);
        if ($backupContent === false) {
            return false;
        }

        $result = file_put_contents($filePath, $backupContent);
        return $result !== false;
    }

    /**
     * Clean up backup files
     * 
     * @param string $backupPath Path to backup file
     * @return bool True if cleanup was successful
     */
    public function cleanupBackup(string $backupPath): bool
    {
        if (file_exists($backupPath)) {
            return unlink($backupPath);
        }
        return true;
    }

    /**
     * Get the backup manager instance
     * 
     * @return BackupManager
     */
    public function getBackupManager(): BackupManager
    {
        return $this->backupManager;
    }

    /**
     * Enable backups with optional configuration
     * 
     * @param array<string, mixed> $config Optional backup configuration
     */
    public function enableBackups(array $config = []): void
    {
        $this->config['createBackup'] = true;
        $this->backupManager->enable($config);
    }

    /**
     * Disable backups
     */
    public function disableBackups(): void
    {
        $this->config['createBackup'] = false;
        $this->backupManager->disable();
    }

    /**
     * Parse file content into AST
     * 
     * @param string $content PHP file content
     * @return array<Node> AST nodes
     */
    private function parseFileToAst(string $content): array
    {
        try {
            $ast = $this->parser->parse($content);
            if ($ast === null) {
                throw new RuntimeException("Failed to parse PHP content");
            }
            return $ast;
        } catch (\PhpParser\Error $e) {
            throw new RuntimeException("PHP parse error: " . $e->getMessage());
        }
    }

    /**
     * Find the location of ->record() call in the AST
     * 
     * @param array<Node> $ast
     * @return InjectionPoint|null
     */
    private function findRecordCallLocation(array $ast): ?InjectionPoint
    {
        $visitor = new RecordCallVisitor();
        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);

        return $visitor->getInjectionPoint();
    }

    /**
     * Parse code to inject into AST nodes
     * 
     * @param string $code PHP code without <?php tags
     * @return array<Node>
     */
    private function parseCodeToInject(string $code): array
    {
        // Wrap the code in <?php tags for parsing
        $wrappedCode = "<?php\n" . $code;
        
        try {
            $ast = $this->parser->parse($wrappedCode);
            if ($ast === null) {
                throw new RuntimeException("Failed to parse code to inject");
            }
            return $ast;
        } catch (\PhpParser\Error $e) {
            throw new RuntimeException("Parse error in code to inject: " . $e->getMessage());
        }
    }

    /**
     * Inject code nodes at the specified location
     * 
     * @param array<Node> $ast Original AST
     * @param InjectionPoint $point Where to inject
     * @param array<Node> $codeNodes Nodes to inject
     * @return array<Node> Modified AST
     */
    private function injectCodeAtLocation(array $ast, InjectionPoint $point, array $codeNodes): array
    {
        $visitor = new CodeInjectionVisitor($point, $codeNodes);
        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        
        $modifiedAst = $traverser->traverse($ast);
        
        if (!$visitor->wasInjected()) {
            throw new RuntimeException("Failed to inject code at the specified location");
        }

        return $modifiedAst;
    }

    /**
     * Convert AST back to PHP code
     * 
     * @param array<Node> $ast
     * @return string
     */
    private function astToPhpCode(array $ast): string
    {
        return $this->printer->prettyPrintFile($ast);
    }

    /**
     * Create a backup of the original file
     * 
     * @param string $filePath Original file path
     * @param string $content File content
     * @return string Backup file path
     */
    private function createBackup(string $filePath, string $content): string
    {
        $backupPath = $filePath . $this->config['backupSuffix'];
        $counter = 1;
        
        // Find a unique backup filename
        while (file_exists($backupPath)) {
            $backupPath = $filePath . $this->config['backupSuffix'] . ".{$counter}";
            $counter++;
        }

        $result = file_put_contents($backupPath, $content);
        if ($result === false) {
            throw new RuntimeException("Failed to create backup: {$backupPath}");
        }

        return $backupPath;
    }

    /**
     * Verify that the injection was successful
     * 
     * @param string $newContent Modified file content
     * @param string $injectedCode Code that was injected
     */
    private function verifyInjection(string $newContent, string $injectedCode): void
    {
        // Verify the modified content is still valid PHP
        try {
            $this->parser->parse($newContent);
        } catch (\PhpParser\Error $e) {
            throw new RuntimeException("Verification failed: modified file contains syntax errors: " . $e->getMessage());
        }

        // Basic verification - check for key parts of the injected code
        // Since formatting may change during AST processing, we check for method calls
        $trimmedCode = trim($injectedCode);
        
        // Extract method calls from the injected code for verification
        if (preg_match_all('/->(\w+)\s*\(/', $trimmedCode, $matches)) {
            foreach ($matches[1] as $methodName) {
                if (strpos($newContent, "->{$methodName}(") === false) {
                    throw new RuntimeException("Verification failed: method call '->{$methodName}()' not found in output");
                }
            }
        } else {
            // Fallback: check if any significant part of the code exists
            $codeWords = preg_split('/\s+/', $trimmedCode);
            $significantWords = array_filter($codeWords, fn($word) => strlen($word) > 3);
            
            if (!empty($significantWords)) {
                $found = false;
                foreach ($significantWords as $word) {
                    if (strpos($newContent, $word) !== false) {
                        $found = true;
                        break;
                    }
                }
                
                if (!$found) {
                    throw new RuntimeException("Verification failed: no significant parts of injected code found in output");
                }
            }
        }
    }
}

/**
 * Visitor to find ->record() method calls
 */
final class RecordCallVisitor extends NodeVisitorAbstract
{
    /**
     * @var InjectionPoint|null Found injection point
     */
    private ?InjectionPoint $injectionPoint = null;

    /**
     * @var array<array<Node>> Stack of statement contexts
     */
    private array $statementStack = [];

    /**
     * @var array<int> Stack of statement indices
     */
    private array $indexStack = [];

    public function enterNode(Node $node)
    {
        // Track statement arrays for injection
        if (property_exists($node, 'stmts') && is_array($node->stmts)) {
            $this->statementStack[] = $node->stmts;
            $this->indexStack[] = 0;
            
            // Process each statement in this context
            foreach ($node->stmts as $index => $stmt) {
                $this->indexStack[count($this->indexStack) - 1] = $index;
                
                // Check if this statement contains a record call
                if ($this->statementContainsRecordCall($stmt)) {
                    $this->injectionPoint = new InjectionPoint(
                        statementArray: $node->stmts,
                        insertAfterIndex: $index,
                        recordCallNode: $stmt,
                        parentNode: $node
                    );
                    break;
                }
            }
        }

        return null;
    }

    public function leaveNode(Node $node)
    {
        // Pop statement context when leaving a node with statements
        if (property_exists($node, 'stmts') && is_array($node->stmts)) {
            array_pop($this->statementStack);
            array_pop($this->indexStack);
        }

        return null;
    }

    /**
     * Check if a statement contains a ->record() method call
     */
    private function statementContainsRecordCall(Node $stmt): bool
    {
        $found = false;
        
        $visitor = new class($found) extends NodeVisitorAbstract {
            public function __construct(private bool &$found) {}

            public function enterNode(Node $node) {
                if ($node instanceof MethodCall && 
                    $node->name instanceof Node\Identifier && 
                    $node->name->name === 'record') {
                    $this->found = true;
                }
                return null;
            }
        };

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse([$stmt]);

        return $found;
    }

    public function getInjectionPoint(): ?InjectionPoint
    {
        return $this->injectionPoint;
    }
}

/**
 * Visitor to inject code at a specific location
 */
final class CodeInjectionVisitor extends NodeVisitorAbstract
{
    /**
     * @var bool Whether injection was performed
     */
    private bool $injected = false;

    /**
     * @param InjectionPoint $injectionPoint Where to inject
     * @param array<Node> $codeNodes Nodes to inject
     */
    public function __construct(
        private InjectionPoint $injectionPoint,
        private array $codeNodes
    ) {
    }

    public function leaveNode(Node $node)
    {
        // Check if this is the parent node we're looking for
        if ($node === $this->injectionPoint->parentNode && 
            property_exists($node, 'stmts') && 
            is_array($node->stmts)) {
            
            $targetIndex = $this->injectionPoint->insertAfterIndex;
            
            // Insert the new nodes after the record call
            array_splice(
                $node->stmts, 
                $targetIndex + 1, 
                0, 
                $this->codeNodes
            );
            
            $this->injected = true;
        }

        return null;
    }

    /**
     * Check if a statement contains the record call we're looking for
     */
    private function containsRecordCall(Node $stmt): bool
    {
        $visitor = new class($this->injectionPoint->recordCallNode) extends NodeVisitorAbstract {
            private bool $found = false;

            public function __construct(private Node $targetNode) {}

            public function enterNode(Node $node) {
                if ($node === $this->targetNode) {
                    $this->found = true;
                }
                return null;
            }

            public function wasFound(): bool {
                return $this->found;
            }
        };

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse([$stmt]);

        return $visitor->wasFound();
    }

    public function wasInjected(): bool
    {
        return $this->injected;
    }
}

/**
 * Represents a location in the AST where code should be injected
 */
final readonly class InjectionPoint
{
    /**
     * @param array<Node> $statementArray Array of statements containing the record call
     * @param int $insertAfterIndex Index after which to insert new code
     * @param Node $recordCallNode The actual ->record() call node
     * @param Node $parentNode The parent node containing the statements
     */
    public function __construct(
        public array $statementArray,
        public int $insertAfterIndex,
        public Node $recordCallNode,
        public Node $parentNode
    ) {
    }
}

/**
 * Result of a code injection operation
 */
final readonly class InjectionResult
{
    public function __construct(
        public bool $success,
        public string $filePath,
        public ?string $backupPath,
        public int $originalSize,
        public int $newSize,
        public ?InjectionPoint $injectionPoint,
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
            'success' => $this->success,
            'filePath' => $this->filePath,
            'backupPath' => $this->backupPath,
            'originalSize' => $this->originalSize,
            'newSize' => $this->newSize,
            'sizeDelta' => $this->newSize - $this->originalSize,
            'injectionPoint' => $this->injectionPoint !== null ? [
                'insertAfterIndex' => $this->injectionPoint->insertAfterIndex,
            ] : null,
            'error' => $this->error,
        ];
    }
}
