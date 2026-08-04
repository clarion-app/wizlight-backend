<?php

namespace ClarionApp\WizlightBackend\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * FR-016 / SC-013: no test in this package may assert on the text of source
 * code.
 *
 * A source-text assertion tests nothing about behaviour — the suite previously
 * "covered" discover()'s response loop with a preg_match for a `break`, which
 * passes if the loop is deleted outright. This file is the one place where
 * scanning source is the correct instrument, because the property under test
 * *is* a property of the source.
 */
class NoSourceTextAssertionsTest extends TestCase
{
    private const FORBIDDEN = [
        'file_get_contents',
        'preg_match',
        'file(',
        'token_get_all',
    ];

    /**
     * @return array<string, string> path => contents
     */
    private function testFiles(): array
    {
        $files = [];
        $directory = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(__DIR__ . '/..')
        );

        foreach ($directory as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            if ($file->getFilename() === basename(__FILE__)) {
                continue;
            }
            $files[$file->getPathname()] = file_get_contents($file->getPathname());
        }

        return $files;
    }

    /** @test */
    public function no_test_file_reads_package_source_as_text()
    {
        $files = $this->testFiles();
        $this->assertNotEmpty($files, 'Precondition: there are test files to scan');

        $offenders = [];
        foreach ($files as $path => $contents) {
            foreach (self::FORBIDDEN as $needle) {
                if (str_contains($contents, $needle)) {
                    $offenders[] = basename($path) . " uses {$needle}";
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "Source-text assertions found. Rewrite these as behaviour against FakeUdpTransport:\n"
                . implode("\n", $offenders)
        );
    }

    /** @test */
    public function no_test_file_reaches_into_the_src_directory()
    {
        $offenders = [];
        foreach ($this->testFiles() as $path => $contents) {
            // loadMigrationsFrom('../../src/Migrations') is legitimate — it runs
            // schema, it does not read source as text. Anything else pointing at
            // src/ is a source-text assertion in disguise.
            if (preg_match_all('#[\'"][^\'"]*src/(?!Migrations)[^\'"]*[\'"]#', $contents, $matches)) {
                foreach ($matches[0] as $match) {
                    $offenders[] = basename($path) . " references {$match}";
                }
            }
        }

        $this->assertSame([], $offenders, implode("\n", $offenders));
    }

    /** @test */
    public function the_retired_colour_types_have_no_references_anywhere()
    {
        // SC-013 / FR-017. Reachability, not text: if the classes are gone and
        // nothing references them, they cannot be resolved.
        foreach (['LightColor', 'RGBColor', 'TemperatureColor'] as $type) {
            $this->assertFalse(
                class_exists('ClarionApp\\WizlightBackend\\' . $type),
                "{$type} was retired (FR-017) and must not be reintroduced"
            );
        }

        $this->assertFalse(
            method_exists(\ClarionApp\WizlightBackend\Wiz::class, 'set_pilot_state'),
            'Wiz::set_pilot_state() was retired with the colour abstraction it depended on'
        );
    }
}
