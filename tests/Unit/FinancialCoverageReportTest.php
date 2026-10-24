<?php

use Tests\TestCase;

uses(TestCase::class);

test('financial coverage cannot omit the newly implemented Intelligence subtree', function () {
    $path = $this->coverageRoot.'/app/Domain/Intelligence';
    mkdir($path, 0700, true);
    file_put_contents($path.'/Boundary.php', '<?php final class Boundary { public function read(): int { return 1; } }');
    try {
        $report = ($this->coverageXml)(($this->coverageFile)('statements="1" coveredstatements="1"', '<line num="1" type="stmt" count="2"/>'));
        expect(fn () => HalalOS\Verification\financialCoverage($report, $this->coverageRoot))->toThrow(RuntimeException::class, 'missing financial coverage app/Domain/Intelligence/Boundary.php');
    } finally {
        unlink($path.'/Boundary.php');
        rmdir($path);
    }
});

require_once __DIR__.'/../../scripts/financial-coverage.php';

beforeEach(function () {
    $this->coverageRoot = sys_get_temp_dir().'/hwo-financial-report-'.bin2hex(random_bytes(8));
    mkdir($this->coverageRoot.'/app/Domain/Ledger', 0700, true);
    file_put_contents($this->coverageRoot.'/app/Domain/Ledger/Calculator.php', '<?php final class Calculator { public function value(): int { return 1; } }');
    $this->coverageXml = function (string $body): DOMDocument {
        $xml = new DOMDocument;
        $xml->loadXML('<coverage><project>'.$body.'</project></coverage>', LIBXML_NONET);

        return $xml;
    };
    $this->coverageFile = function (string $metrics, string $lines): string {
        return '<file name="'.htmlspecialchars($this->coverageRoot.'/app/Domain/Ledger/Calculator.php', ENT_XML1).'">'.$lines.'<metrics '.$metrics.'/></file>';
    };
});
afterEach(function () {
    foreach (glob($this->coverageRoot.'/app/Domain/Ledger/*') as $file) {
        unlink($file);
    }
    foreach (['/app/Domain/Ledger', '/app/Domain', '/app', ''] as $suffix) {
        rmdir($this->coverageRoot.$suffix);
    }
});

test('financial report accepts executed concrete calculator and explicitly identifies interface-only sources', function () {
    file_put_contents($this->coverageRoot.'/app/Domain/Ledger/Reader.php', '<?php interface Reader { public function read(): int; }');
    $report = ($this->coverageXml)(($this->coverageFile)('statements="1" coveredstatements="1"', '<line num="1" type="stmt" count="2"/>'));
    $rows = HalalOS\Verification\financialCoverage($report, $this->coverageRoot);
    expect($rows)->toHaveCount(2)->and($rows[0]['covered'])->toBe(1)->and($rows[1]['status'])->toBe('INTERFACE_NO_EXECUTABLE_LINES');
});
test('financial report includes covered promoted constructor executable lines emitted as Clover methods', function () {
    file_put_contents($this->coverageRoot.'/app/Domain/Ledger/Calculator.php', '<?php final class Calculator { public function __construct(public int $value) {} }');
    $report = ($this->coverageXml)(($this->coverageFile)('statements="1" coveredstatements="1"', '<line num="1" type="method" name="__construct" count="3"/>'));
    expect(HalalOS\Verification\financialCoverage($report, $this->coverageRoot)[0])->toMatchArray(['covered' => 1, 'executable' => 1, 'status' => 'PASS']);
});
test('financial report rejects missing unexecuted inconsistent and below-floor calculator coverage', function (string $metrics, string $lines) {
    $report = ($this->coverageXml)(($this->coverageFile)($metrics, $lines));
    expect(fn () => HalalOS\Verification\financialCoverage($report, $this->coverageRoot))->toThrow(RuntimeException::class);
})->with([
    ['statements="0" coveredstatements="0"', ''],
    ['statements="1" coveredstatements="0"', '<line num="1" type="stmt" count="0"/>'],
    ['statements="2" coveredstatements="2"', '<line num="1" type="stmt" count="2"/>'],
    ['statements="1" coveredstatements="1"', '<line num="1" type="stmt" count="0"/>'],
    ['statements="1" coveredstatements="1"', '<line num="1" type="stmt" count="-1"/>'],
]);
test('financial report refuses an omitted newly discovered financial class and duplicate coverage paths', function () {
    $file = ($this->coverageFile)('statements="1" coveredstatements="1"', '<line num="1" type="stmt" count="2"/>');
    expect(fn () => HalalOS\Verification\financialCoverage(($this->coverageXml)($file.$file), $this->coverageRoot))->toThrow(RuntimeException::class);
    file_put_contents($this->coverageRoot.'/app/Domain/Ledger/AddedCalculator.php', '<?php final class AddedCalculator { public function value(): int { return 2; } }');
    expect(fn () => HalalOS\Verification\financialCoverage(($this->coverageXml)($file), $this->coverageRoot))->toThrow(RuntimeException::class);
});
