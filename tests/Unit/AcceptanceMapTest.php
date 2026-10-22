<?php

use Tests\TestCase;

uses(TestCase::class);

require_once __DIR__.'/../../scripts/acceptance-map.php';

beforeEach(function () {
    $this->acceptanceMap = json_decode(file_get_contents(base_path('scripts/acceptance-map.json')), true, flags: JSON_THROW_ON_ERROR);
    $this->acceptanceJunit = new DOMDocument;
    $suite = $this->acceptanceJunit->appendChild($this->acceptanceJunit->createElement('testsuite'));
    foreach ($this->acceptanceMap['cases'] as $selector) {
        $case = $suite->appendChild($this->acceptanceJunit->createElement('testcase'));
        foreach (['class', 'name', 'file'] as $attribute) {
            $case->setAttribute($attribute, $selector[$attribute]);
        }
        $case->setAttribute('assertions', '1');
    }
});

test('acceptance map resolves exact configured cases and explicit retained evidence without claiming a tool pass', function () {
    $report = HalalOS\Verification\acceptanceMap($this->acceptanceMap, $this->acceptanceJunit, base_path());
    expect($report['requirements'])->toHaveCount(97)->and($report)->not->toHaveKey('result');
});

test('acceptance map refuses missing renamed skipped or zero-assertion executed cases', function (string $fault) {
    $case = $this->acceptanceJunit->getElementsByTagName('testcase')->item(0);
    match ($fault) {
        'missing' => $case->parentNode->removeChild($case),
        'renamed' => $case->setAttribute('name', $case->getAttribute('name').' changed'),
        'skipped' => $case->appendChild($this->acceptanceJunit->createElement('skipped')),
        'zero-assertions' => $case->setAttribute('assertions', '0'),
    };
    expect(fn () => HalalOS\Verification\acceptanceMap($this->acceptanceMap, $this->acceptanceJunit, base_path()))->toThrow(RuntimeException::class);
})->with(['missing', 'renamed', 'skipped', 'zero-assertions']);

test('acceptance map refuses omitted criteria changed manual evidence and unsupported fixture pass claims', function (string $fault) {
    if ($fault === 'omitted') {
        unset($this->acceptanceMap['requirements']['INV-10']);
    } elseif ($fault === 'manual') {
        $key = array_key_first($this->acceptanceMap['manual_artifacts']);
        $this->acceptanceMap['manual_artifacts'][$key]['sha256'] = str_repeat('0', 64);
    } elseif ($fault === 'null-evidence') {
        $this->acceptanceMap['requirements']['M0-P1-AC1']['actual_case_ids'] = null;
        $this->acceptanceMap['requirements']['M0-P1-AC1']['manual_artifact_ids'] = null;
    } elseif ($fault === 'missing-fixture') {
        unset($this->acceptanceMap['fixtures']['F16']);
    } else {
        $this->acceptanceMap['fixtures']['F17']['status'] = 'PASS';
    }
    expect(fn () => HalalOS\Verification\acceptanceMap($this->acceptanceMap, $this->acceptanceJunit, base_path()))->toThrow(RuntimeException::class);
})->with(['omitted', 'manual', 'future', 'null-evidence', 'missing-fixture']);
