<?php

namespace Warden\Tests\Unit;

use Warden\Services\AstExtractor;
use Warden\Tests\TestCase;

class AstExtractorTest extends TestCase
{
    protected AstExtractor $extractor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->extractor = new AstExtractor();
    }

    public function test_parse_valid_php_code(): void
    {
        $code = '<?php class Foo { public function bar() {} }';
        $ast = $this->extractor->parse($code);

        $this->assertIsArray($ast);
        $this->assertNotEmpty($ast);
    }

    public function test_parse_invalid_php_code_returns_null(): void
    {
        $code = '<?php class { }'; // Invalid syntax
        $ast = $this->extractor->parse($code);

        $this->assertNull($ast);
    }

    public function test_extract_class_symbol(): void
    {
        $code = '<?php namespace App; class MyClass {}';
        $ast = $this->extractor->parse($code);
        $symbols = $this->extractor->extractSymbols($ast, 'test.php');

        $this->assertCount(1, $symbols);
        $this->assertEquals('class', $symbols[0]['type']);
        $this->assertEquals('MyClass', $symbols[0]['name']);
        $this->assertEquals('App\\MyClass', $symbols[0]['fqcn']);
    }

    public function test_extract_method_symbol(): void
    {
        $code = '<?php namespace App; class MyClass { public function myMethod() {} }';
        $ast = $this->extractor->parse($code);
        $symbols = $this->extractor->extractSymbols($ast, 'test.php');

        $this->assertCount(2, $symbols);

        $methodSymbol = null;
        foreach ($symbols as $symbol) {
            if ($symbol['type'] === 'class_method') {
                $methodSymbol = $symbol;
                break;
            }
        }
        $this->assertNotNull($methodSymbol);
        $this->assertEquals('myMethod', $methodSymbol['name']);
        $this->assertEquals('App\\MyClass::myMethod', $methodSymbol['fqcn']);
    }

    public function test_extract_function_symbol(): void
    {
        $code = '<?php namespace App; function myFunction() {}';
        $ast = $this->extractor->parse($code);
        $symbols = $this->extractor->extractSymbols($ast, 'test.php');

        $this->assertCount(1, $symbols);
        $this->assertEquals('function', $symbols[0]['type']);
        $this->assertEquals('myFunction', $symbols[0]['name']);
        $this->assertEquals('App\\myFunction', $symbols[0]['fqcn']);
    }

    public function test_extract_interface_symbol(): void
    {
        $code = '<?php namespace App; interface MyInterface {}';
        $ast = $this->extractor->parse($code);
        $symbols = $this->extractor->extractSymbols($ast, 'test.php');

        $this->assertCount(1, $symbols);
        $this->assertEquals('interface', $symbols[0]['type']);
        $this->assertEquals('App\\MyInterface', $symbols[0]['fqcn']);
    }

    public function test_extract_trait_symbol(): void
    {
        $code = '<?php namespace App; trait MyTrait {}';
        $ast = $this->extractor->parse($code);
        $symbols = $this->extractor->extractSymbols($ast, 'test.php');

        $this->assertCount(1, $symbols);
        $this->assertEquals('trait', $symbols[0]['type']);
        $this->assertEquals('App\\MyTrait', $symbols[0]['fqcn']);
    }

    public function test_extract_enum_symbol(): void
    {
        $code = '<?php namespace App; enum Status { case Active; case Inactive; }';
        $ast = $this->extractor->parse($code);
        $symbols = $this->extractor->extractSymbols($ast, 'test.php');

        $this->assertCount(1, $symbols);
        $this->assertEquals('enum', $symbols[0]['type']);
        $this->assertEquals('App\\Status', $symbols[0]['fqcn']);
    }

    public function test_extract_docblock(): void
    {
        $code = '<?php namespace App; /** This is a doc comment */ class MyClass {}';
        $ast = $this->extractor->parse($code);
        $symbols = $this->extractor->extractSymbols($ast, 'test.php');

        $this->assertStringContainsString('This is a doc comment', $symbols[0]['docblock']);
    }

    public function test_build_symbol_id(): void
    {
        $payload = [
            'file' => 'test.php',
            'type' => 'class',
            'fqcn' => 'App\\MyClass',
        ];

        $id1 = $this->extractor->buildSymbolId('main', $payload);
        $id2 = $this->extractor->buildSymbolId('main', $payload);

        $this->assertEquals($id1, $id2);
        $this->assertEquals(32, strlen($id1)); // MD5 hash length
    }

    public function test_build_symbol_key(): void
    {
        $payload = ['fqcn' => 'App\\MyClass'];
        $this->assertEquals('App\\MyClass', $this->extractor->buildSymbolKey($payload));

        $payload = ['class' => 'MyClass', 'function' => 'myMethod'];
        $this->assertEquals('MyClass::myMethod', $this->extractor->buildSymbolKey($payload));

        $payload = ['function' => 'myFunction'];
        $this->assertEquals('myFunction', $this->extractor->buildSymbolKey($payload));

        $payload = [];
        $this->assertNull($this->extractor->buildSymbolKey($payload));
    }

    public function test_build_embedding_input(): void
    {
        $symbol = [
            'type' => 'class_method',
            'fqcn' => 'App\\MyClass::myMethod',
            'docblock' => '/** My doc */',
        ];
        $snippet = 'public function myMethod() {}';

        $input = $this->extractor->buildEmbeddingInput($symbol, $snippet);

        $this->assertStringContainsString('Type: class_method', $input);
        $this->assertStringContainsString('Name: App\\MyClass::myMethod', $input);
        $this->assertStringContainsString('/** My doc */', $input);
        $this->assertStringContainsString('public function myMethod() {}', $input);
    }
}
