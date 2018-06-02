<?php

namespace Phpactor\CodeTransform\Tests\Adapter\TolerantParser\ClassToFile\Transformer;

use Phpactor\ClassFileConverter\Adapter\Composer\ComposerFileToClass;
use Phpactor\ClassFileConverter\Adapter\Simple\SimpleFileToClass;
use Phpactor\ClassFileConverter\Domain\ClassToFile;
use Phpactor\ClassFileConverter\Domain\FileToClass;
use Phpactor\CodeTransform\Adapter\TolerantParser\ClassToFile\Transformer\ClassNameFixerTransformer;
use Phpactor\CodeTransform\Adapter\TolerantParser\ClassToFile\Transformer\FixNameTransformer;
use Phpactor\CodeTransform\Domain\SourceCode;
use Phpactor\CodeTransform\Tests\Adapter\AdapterTestCase;
use Phpactor\TestUtils\Workspace;
use RuntimeException;

class ClassNameFixerTransformerTest extends AdapterTestCase
{
    private static $composerAutoload;

    /**
     * @dataProvider provideFixClassName
     */
    public function testFixClassName(string $filePath, string $test)
    {
        $workspace = $this->workspace();
        $workspace->reset();
        $workspace->loadManifest(file_get_contents(__DIR__ . '/fixtures/' . $test));
        $source = $workspace->getContents($filePath);
        $expected = $workspace->getContents('expected');

        $autoload = $this->initComposer($workspace);

        $fileToClass = new ComposerFileToClass($autoload);
        $transformer = new ClassNameFixerTransformer($fileToClass);
        $transformed = $transformer->transform(SourceCode::fromStringAndPath($source, $this->workspace()->path($filePath)));

        $this->assertEquals(trim($expected), trim($transformed));
    }

    public function provideFixClassName()
    {
        yield 'no op' => [ 'FileOne.php', 'fixNamespace0.test' ];
        yield 'fix file with missing namespace' => [ 'PathTo/FileOne.php', 'fixNamespace1.test' ];
        yield 'fix file with namespace' => [ 'PathTo/FileOne.php', 'fixNamespace2.test' ];
        yield 'fix class name' => [ 'FileOne.php', 'fixNamespace3.test' ];
        yield 'fix class name with same line bracket' => [ 'FileOne.php', 'fixNamespace4.test' ];
        yield 'fix class name and namespace' => [ 'Phpactor/Test/Foobar/FileOne.php', 'fixNamespace5.test' ];
    }

    public function testThrowsExceptionIfSourceCodeHasNoPath()
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Source code has no path');
        $transformer = new ClassNameFixerTransformer(new SimpleFileToClass(__DIR__));
        $transformed = $transformer->transform(SourceCode::fromString('hello'));
    }

    private function initComposer(Workspace $workspace)
    {
        if (self::$composerAutoload) {
            return self::$composerAutoload;
        }

        $composer = <<<'EOT'
{
"autoload": {
    "psr-4": {
        "": ""
    }
}
}
EOT
        ;
        file_put_contents($workspace->path('/composer.json'), $composer);
        $cwd = getcwd();
        chdir($workspace->path('/'));
        exec('composer dumpautoload');
        chdir($cwd);
        self::$composerAutoload = require_once($workspace->path('/vendor/autoload.php'));

        return $this->initComposer($workspace);
    }
}