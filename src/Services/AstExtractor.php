<?php

namespace Warden\Services;

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;

class AstExtractor
{
    protected \PhpParser\Parser $parser;

    public function __construct()
    {
        $this->parser = (new ParserFactory)->createForNewestSupportedVersion();
    }

    /**
     * Parse PHP code and return the AST.
     *
     * @return Node[]|null
     */
    public function parse(string $code): ?array
    {
        try {
            return $this->parser->parse($code);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Extract symbol metadata from an AST.
     *
     * @param  Node[]  $ast
     * @return array<int, array{type: string, name: string, file: string, fqcn: string|null, class: string|null, function: string|null, start_line: int, end_line: int, docblock: string|null}>
     */
    public function extractSymbols(array $ast, string $relPath): array
    {
        $symbols = [];
        $currentNamespace = '';
        $currentClass = null;

        $traverser = new NodeTraverser;
        $visitor = new class($symbols, $relPath, $currentNamespace, $currentClass) extends NodeVisitorAbstract
        {
            /** @var array<int, array<string, mixed>> */
            public array $symbols = [];

            public string $relPath;

            public string $currentNamespace = '';

            public ?string $currentClass = null;

            /**
             * @param  array<int, array<string, mixed>>  $symbols
             */
            public function __construct(
                array &$symbols,
                string $relPath,
                string &$currentNamespace,
                ?string &$currentClass
            ) {
                $this->symbols = &$symbols;
                $this->relPath = $relPath;
                $this->currentNamespace = &$currentNamespace;
                $this->currentClass = &$currentClass;
            }

            public function enterNode(Node $node): ?int
            {
                if ($node instanceof Node\Stmt\Namespace_) {
                    $this->currentNamespace = $node->name ? $node->name->toString() : '';
                }

                if ($node instanceof Node\Stmt\Class_
                    || $node instanceof Node\Stmt\Interface_
                    || $node instanceof Node\Stmt\Trait_
                    || $node instanceof Node\Stmt\Enum_
                ) {
                    $name = $node->name ? $node->name->toString() : 'anonymous';
                    $this->currentClass = $name;
                    $fqcn = $this->currentNamespace ? $this->currentNamespace.'\\'.$name : $name;

                    // Determine type based on node type
                    if ($node instanceof Node\Stmt\Class_) {
                        $type = 'class';
                    } elseif ($node instanceof Node\Stmt\Interface_) {
                        $type = 'interface';
                    } elseif ($node instanceof Node\Stmt\Trait_) {
                        $type = 'trait';
                    } else {
                        // Must be Enum_ based on the if condition above
                        $type = 'enum';
                    }

                    $this->symbols[] = [
                        'type' => $type,
                        'name' => $name,
                        'file' => $this->relPath,
                        'fqcn' => $fqcn,
                        'class' => $name,
                        'function' => null,
                        'start_line' => $node->getStartLine(),
                        'end_line' => $node->getEndLine(),
                        'docblock' => $this->getDocComment($node),
                    ];
                }

                if ($node instanceof Node\Stmt\ClassMethod) {
                    $methodName = $node->name->toString();
                    $className = $this->currentClass ?? 'unknown';
                    $fqcn = $this->currentNamespace
                        ? $this->currentNamespace.'\\'.$className.'::'.$methodName
                        : $className.'::'.$methodName;

                    $this->symbols[] = [
                        'type' => 'class_method',
                        'name' => $methodName,
                        'file' => $this->relPath,
                        'fqcn' => $fqcn,
                        'class' => $className,
                        'function' => $methodName,
                        'start_line' => $node->getStartLine(),
                        'end_line' => $node->getEndLine(),
                        'docblock' => $this->getDocComment($node),
                    ];
                }

                if ($node instanceof Node\Stmt\Function_) {
                    $funcName = $node->name->toString();
                    $fqcn = $this->currentNamespace
                        ? $this->currentNamespace.'\\'.$funcName
                        : $funcName;

                    $this->symbols[] = [
                        'type' => 'function',
                        'name' => $funcName,
                        'file' => $this->relPath,
                        'fqcn' => $fqcn,
                        'class' => null,
                        'function' => $funcName,
                        'start_line' => $node->getStartLine(),
                        'end_line' => $node->getEndLine(),
                        'docblock' => $this->getDocComment($node),
                    ];
                }

                if ($node instanceof Node\Stmt\Property) {
                    $className = $this->currentClass ?? 'unknown';

                    foreach ($node->props as $prop) {
                        $propName = $prop->name->toString();
                        $fqcn = $this->currentNamespace
                            ? $this->currentNamespace.'\\'.$className.'::$'.$propName
                            : $className.'::$'.$propName;

                        $this->symbols[] = [
                            'type' => 'property',
                            'name' => $propName,
                            'file' => $this->relPath,
                            'fqcn' => $fqcn,
                            'class' => $className,
                            'function' => null,
                            'start_line' => $node->getStartLine(),
                            'end_line' => $node->getEndLine(),
                            'docblock' => $this->getDocComment($node),
                        ];
                    }
                }

                if ($node instanceof Node\Stmt\Const_) {
                    foreach ($node->consts as $const) {
                        $constName = $const->name->toString();
                        $fqcn = $this->currentNamespace
                            ? $this->currentNamespace.'\\'.$constName
                            : $constName;

                        $this->symbols[] = [
                            'type' => 'constant',
                            'name' => $constName,
                            'file' => $this->relPath,
                            'fqcn' => $fqcn,
                            'class' => null,
                            'function' => null,
                            'start_line' => $node->getStartLine(),
                            'end_line' => $node->getEndLine(),
                            'docblock' => $this->getDocComment($node),
                        ];
                    }
                }

                if ($node instanceof Node\Stmt\ClassConst) {
                    $className = $this->currentClass ?? 'unknown';

                    foreach ($node->consts as $const) {
                        $constName = $const->name->toString();
                        $fqcn = $this->currentNamespace
                            ? $this->currentNamespace.'\\'.$className.'::'.$constName
                            : $className.'::'.$constName;

                        $this->symbols[] = [
                            'type' => 'class_constant',
                            'name' => $constName,
                            'file' => $this->relPath,
                            'fqcn' => $fqcn,
                            'class' => $className,
                            'function' => null,
                            'start_line' => $node->getStartLine(),
                            'end_line' => $node->getEndLine(),
                            'docblock' => $this->getDocComment($node),
                        ];
                    }
                }

                return null;
            }

            public function leaveNode(Node $node): ?int
            {
                if ($node instanceof Node\Stmt\Class_
                    || $node instanceof Node\Stmt\Interface_
                    || $node instanceof Node\Stmt\Trait_
                    || $node instanceof Node\Stmt\Enum_
                ) {
                    $this->currentClass = null;
                }

                return null;
            }

            protected function getDocComment(Node $node): ?string
            {
                $docComment = $node->getDocComment();

                return $docComment ? $docComment->getText() : null;
            }
        };

        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);

        /** @var array<int, array{type: string, name: string, file: string, fqcn: string|null, class: string|null, function: string|null, start_line: int, end_line: int, docblock: string|null}> $symbols */
        $symbols = $visitor->symbols;

        return $symbols;
    }

    /**
     * Slice file contents by line range.
     */
    public function sliceFileByLines(string $filePath, int $startLine, int $endLine): string
    {
        $lines = file($filePath, FILE_IGNORE_NEW_LINES);

        if ($lines === false) {
            return '';
        }

        // Convert to 0-indexed
        $startIndex = max(0, $startLine - 1);
        $endIndex = min(count($lines), $endLine);

        $selectedLines = array_slice($lines, $startIndex, $endIndex - $startIndex);

        return implode("\n", $selectedLines);
    }

    /**
     * Build embedding input text from symbol and snippet.
     *
     * @param  array<string, mixed>  $symbol
     */
    public function buildEmbeddingInput(array $symbol, string $snippet): string
    {
        $parts = [];

        // Add type context
        $parts[] = "Type: {$symbol['type']}";

        // Add FQCN if available
        if (! empty($symbol['fqcn'])) {
            $parts[] = "Name: {$symbol['fqcn']}";
        }

        // Add docblock if available
        if (! empty($symbol['docblock'])) {
            $parts[] = "Documentation:\n{$symbol['docblock']}";
        }

        // Add the code snippet
        $parts[] = "Code:\n{$snippet}";

        return implode("\n\n", $parts);
    }

    /**
     * Build a stable ID for a symbol.
     *
     * @param  array<string, mixed>  $payload
     */
    public function buildSymbolId(string $branch, array $payload): string
    {
        $parts = [
            $branch,
            $payload['file'],
            $payload['type'],
            $payload['fqcn'] ?? $payload['function'] ?? $payload['class'] ?? 'unknown',
        ];

        return md5(implode(':', $parts));
    }

    /**
     * Build a symbol key for the index map.
     *
     * @param  array<string, mixed>  $payload
     */
    public function buildSymbolKey(array $payload): ?string
    {
        if (! empty($payload['fqcn'])) {
            return $payload['fqcn'];
        }

        if (! empty($payload['class']) && ! empty($payload['function'])) {
            return $payload['class'].'::'.$payload['function'];
        }

        if (! empty($payload['function'])) {
            return $payload['function'];
        }

        if (! empty($payload['class'])) {
            return $payload['class'];
        }

        return null;
    }
}
