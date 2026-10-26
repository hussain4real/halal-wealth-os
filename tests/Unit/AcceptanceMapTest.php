<?php

use Tests\TestCase;

uses(TestCase::class);

test('F18 refuses missing boundary families expanded scope and unrelated evidence', function (string $fault) {
    $f = &$this->acceptanceMap['fixtures']['F18'];
    if ($fault === 'empty') {
        $f['case_ids'] = [];
    } elseif ($fault === 'unrelated') {
        $f['case_ids'] = [array_key_first($this->acceptanceMap['cases'])];
    } elseif ($fault === 'expanded') {
        $f['implemented_subscenarios'][] = 'live_model_answers';
    } elseif ($fault === 'future') {
        $f['future_subscenarios'] = ['M7-P3'];
    } elseif ($fault === 'status') {
        $f['status'] = 'PASS';
    } else {
        $f['case_ids'] = array_values(array_filter($f['case_ids'], fn ($id) => ! str_starts_with($this->acceptanceMap['cases'][$id]['name'], 'provider malicious')));
    }
    expect(fn () => HalalOS\Verification\acceptanceMap($this->acceptanceMap, $this->acceptanceJunit, base_path()))->toThrow(RuntimeException::class);
})->with(['empty', 'unrelated', 'expanded', 'future', 'status', 'family']);

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
    expect($report['requirements'])->toHaveCount(137)->and($report)->not->toHaveKey('result');
    expect(array_column($report['fixtures'], 'status'))->toBe(array_column($this->acceptanceMap['fixtures'], 'status'));
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

test('acceptance map refuses nonpassing missing or malformed applicable fixture statuses', function (string $fixtureId) {
    foreach (['FAILED', 'NOT_IMPLEMENTED', 'FUTURE_M6_NOT_IMPLEMENTED', 'PASS', '', null, false, []] as $status) {
        $map = $this->acceptanceMap;
        $map['fixtures'][$fixtureId]['status'] = $status;
        expect(fn () => HalalOS\Verification\acceptanceMap($map, $this->acceptanceJunit, base_path()))->toThrow(RuntimeException::class, 'BLOCKED: applicable fixture is not passing '.$fixtureId);
    }
    $map = $this->acceptanceMap;
    unset($map['fixtures'][$fixtureId]['status']);
    expect(fn () => HalalOS\Verification\acceptanceMap($map, $this->acceptanceJunit, base_path()))->toThrow(RuntimeException::class, 'BLOCKED: applicable fixture is not passing '.$fixtureId);
})->with(array_map(fn (int $i): string => sprintf('F%02d', $i), range(1, 16)));

test('acceptance map refuses invalid configured requirement status', function () {
    $this->acceptanceMap['requirements']['INV-10']['status'] = 'FAILED';
    expect(fn () => HalalOS\Verification\acceptanceMap($this->acceptanceMap, $this->acceptanceJunit, base_path()))->toThrow(RuntimeException::class, 'BLOCKED: invalid configured acceptance status INV-10');
});

test('acceptance map refuses expanded or unexecuted F17 provider and outbox scope', function (string $fault) {
    if ($fault === 'expanded') {
        $this->acceptanceMap['fixtures']['F17']['implemented_subscenarios'][] = 'live_email';
    } elseif ($fault === 'future-omitted') {
        $this->acceptanceMap['fixtures']['F17']['future_subscenarios'] = ['outbox_delivery_retry'];
    } elseif ($fault === 'empty') {
        $this->acceptanceMap['fixtures']['F17']['case_ids'] = [];
    } else {
        $this->acceptanceMap['fixtures']['F17']['case_ids'] = [array_key_first($this->acceptanceMap['cases'])];
    }
    expect(fn () => HalalOS\Verification\acceptanceMap($this->acceptanceMap, $this->acceptanceJunit, base_path()))->toThrow(RuntimeException::class);
})->with(['expanded', 'future-omitted', 'empty', 'unrelated']);
