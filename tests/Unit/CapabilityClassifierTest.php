<?php

namespace ClarionApp\WizlightBackend\Tests\Unit;

use Orchestra\Testbench\TestCase;
use ClarionApp\WizlightBackend\Capability\CapabilityClassifier;
use ClarionApp\WizlightBackend\Capability\CapabilityClass;

/**
 * Table-driven classifier cases built from contracts/device-capability-protocol.md
 * fixtures and research.md's precedence rules.
 */
class CapabilityClassifierTest extends TestCase
{
    protected function getPackageProviders($app)
    {
        return [
            \ClarionApp\WizlightBackend\WizlightBackendServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app)
    {
        $app['config']->set('app.url', 'http://localhost');
    }

    /**
     * @test
     * @dataProvider classificationCases
     */
    public function classify_returns_expected_capability_class(
        string $testName,
        ?string $moduleName,
        ?int $warmthMin,
        ?int $warmthMax,
        string $expected
    ) {
        $classifier = new CapabilityClassifier();
        $result = $classifier->classify($moduleName, $warmthMin, $warmthMax);

        $this->assertSame(
            $expected,
            $result,
            $testName
        );
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function classificationCases(): array
    {
        return [
            'RGB name maps to full_colour' => [
                'moduleName contains RGB → full_colour',
                'ESP01_SHRGB_03',
                2200,
                6500,
                CapabilityClass::FULL_COLOUR,
            ],
            'TW name maps to tunable_white' => [
                'moduleName contains TW → tunable_white',
                'ESP03_SHTW1_01ABI',
                2700,
                5000,
                CapabilityClass::TUNABLE_WHITE,
            ],
            'DW name maps to dim_only' => [
                'moduleName contains DW → dim_only',
                'ESP06_SHDW1_31',
                null,
                null,
                CapabilityClass::DIM_ONLY,
            ],
            'unknown name with no warmth range falls to dim_only' => [
                'unrecognized moduleName + null range → dim_only (FR-007 fallback)',
                'ESP99_XYZ1_01',
                null,
                null,
                CapabilityClass::DIM_ONLY,
            ],
            'unknown name with non-equal warmth range upgrades to tunable_white' => [
                'unrecognized moduleName + [2700, 5000] → tunable_white (one-way upgrade)',
                'ESP99_XYZ1_01',
                2700,
                5000,
                CapabilityClass::TUNABLE_WHITE,
            ],
            'DW name with non-equal warmth range upgrades to tunable_white' => [
                'DW moduleName + [2700, 5000] → tunable_white (range upgrade)',
                'ESP06_SHDW1_31',
                2700,
                5000,
                CapabilityClass::TUNABLE_WHITE,
            ],
            'null moduleName with no warmth range falls to dim_only' => [
                'null moduleName + null range → dim_only',
                null,
                null,
                null,
                CapabilityClass::DIM_ONLY,
            ],
            'empty moduleName with no warmth range falls to dim_only' => [
                'empty moduleName + null range → dim_only',
                '',
                null,
                null,
                CapabilityClass::DIM_ONLY,
            ],
            'never downgrades from name: full_colour name with null range stays full_colour' => [
                'RGB moduleName + null range → full_colour (name is primary signal)',
                'ESP01_SHRGB_03',
                null,
                null,
                CapabilityClass::FULL_COLOUR,
            ],
            'never downgrades from name: tunable_white name with null range stays tunable_white' => [
                'TW moduleName + null range → tunable_white (name is primary signal)',
                'ESP03_SHTW1_01ABI',
                null,
                null,
                CapabilityClass::TUNABLE_WHITE,
            ],
            'never upgrades to full_colour from range alone: unknown name with wide range caps at tunable_white' => [
                'unrecognized moduleName + [2000, 7000] → tunable_white (range cannot reach full_colour)',
                'ESP99_XYZ1_01',
                2000,
                7000,
                CapabilityClass::TUNABLE_WHITE,
            ],
            'never upgrades to full_colour from range alone: DW name with wide range caps at tunable_white' => [
                'DW moduleName + [2000, 7000] → tunable_white (range cannot reach full_colour)',
                'ESP06_SHDW1_31',
                2000,
                7000,
                CapabilityClass::TUNABLE_WHITE,
            ],
            'equal warmth range does not trigger upgrade: unknown name with [3000, 3000] stays dim_only' => [
                'unrecognized moduleName + equal range → dim_only (no tunable range)',
                'ESP99_XYZ1_01',
                3000,
                3000,
                CapabilityClass::DIM_ONLY,
            ],
            'equal warmth range does not trigger upgrade: DW name with [3000, 3000] stays dim_only' => [
                'DW moduleName + equal range → dim_only (no tunable range)',
                'ESP06_SHDW1_31',
                3000,
                3000,
                CapabilityClass::DIM_ONLY,
            ],
        ];
    }
}
