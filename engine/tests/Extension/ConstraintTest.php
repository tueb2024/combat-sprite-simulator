<?php

namespace JumPHP\Tests\Extension;

use JumPHP\Extension\Constraint;
use JumPHP\Extension\EngineVersion;
use JumPHP\Extension\Extensions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ConstraintTest extends TestCase
{
    /** @return array<string, array{string, string, bool}> */
    public static function cases(): array
    {
        return [
            'caret 0.x same minor' => ['0.5.7', '^0.5', true],
            'caret 0.x next minor' => ['0.6.0', '^0.5', false],
            'caret 0.x.y lower bound' => ['0.5.1', '^0.5.2', false],
            'caret 1.x' => ['1.9.3', '^1.2', true],
            'caret 1.x next major' => ['2.0.0', '^1.2', false],
            'tilde patch' => ['0.5.9', '~0.5.2', true],
            'tilde patch next minor' => ['0.6.0', '~0.5.2', false],
            'range and' => ['0.6.1', '>=0.5 <0.7', true],
            'range comma and out' => ['0.7.0', '>=0.5, <0.7', false],
            'or' => ['0.9.0', '^0.5 || ^0.9', true],
            'wildcard' => ['0.5.12', '0.5.*', true],
            'wildcard out' => ['0.6.0', '0.5.*', false],
            'exact' => ['0.5.7', '0.5.7', true],
            'exact out' => ['0.5.8', '0.5.7', false],
            'any' => ['9.9.9', '*', true],
            'prerelease' => ['0.6.0-beta', '>=0.6', true],
            'gt' => ['0.5.7', '>0.5.7', false],
            'lt' => ['0.5.7', '<0.6', true],
        ];
    }

    #[DataProvider('cases')]
    public function testConstraints(string $version, string $constraint, bool $expected): void
    {
        self::assertSame($expected, Constraint::satisfies($version, $constraint), "$version against $constraint");
    }

    public function testAnExtensionForAnotherEngineIsReported(): void
    {
        $list = [
            ['name' => 'Old', 'package' => 'acme/old', 'dir' => '/x', 'jumphp' => ['engine' => '^0.4']],
            ['name' => 'Fine', 'package' => 'acme/fine', 'dir' => '/x', 'jumphp' => ['engine' => '^0.5']],
            ['name' => 'Silent', 'package' => 'acme/silent', 'dir' => '/x', 'jumphp' => []],
        ];
        $extensions = new Extensions($list);
        self::assertSame([['package' => 'acme/old', 'requires' => '^0.4', 'engine' => '0.5.7']], $extensions->incompatible('0.5.7'));
        self::assertSame(['acme/fine'], array_column($extensions->incompatible('0.4.9'), 'package'), 'at 0.4.9 only the ^0.5 one is out');
    }

    public function testNoVersionNoCheck(): void
    {
        self::assertNull(EngineVersion::current(sys_get_temp_dir() . '/jumphp-no-version-file'));
    }
}
