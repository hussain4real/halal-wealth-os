<?php

use App\Domain\Imports\StatementImports;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Page;
use Tests\Support\FxFixtures;
use Tests\Support\StatementFixtures as S;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);
beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->bank = FxFixtures::account($this->owner);
});

test('statement import pages require authentication and verified email', function () {
    foreach (['/imports', '/imports/1', '/imports/1/export', '/imports/receipts/1', '/imports/requests/synthetic-request'] as $path) {
        $this->get($path)->assertRedirect('/login');
    }
    $unverified = User::factory()->unverified()->create();
    $this->actingAs($unverified)->get('/imports')->assertRedirectToRoute('verification.notice');
    $this->actingAs($this->owner)->get('/imports')->assertOk()->assertInertia(fn (Page $p) => $p->component('imports/Index')->has('accounts', 1)->missing('sources')->missing('raw_csv'));
    app()->instance('env', 'production');
    try {
        $this->get('/imports')->assertRedirectToRoute('security.edit');
    } finally {
        app()->instance('env', 'testing');
    }
});

test('HTTP upload inspect preview selection receipt retry and safe CSV remain private without session input', function () {
    $bytes = S::bytes([['provider_id' => 'SYNTHETIC-HTTP', 'description' => '=SUM(1+1)'], ['amount' => '-1']]);
    $this->actingAs($this->owner)->post('/imports/inspect', ['file' => UploadedFile::fake()->createWithContent('statement.csv', $bytes), 'delimiter' => ','])->assertOk()->assertJsonPath('row_count', 2);
    $uploaded = $this->post('/imports', ['file' => UploadedFile::fake()->createWithContent('statement.csv', $bytes), 'namespace' => 'Synthetic HTTP source', 'account_id' => (string) $this->bank->id,
        'adapter_name' => 'Synthetic HTTP mapping', 'mapping' => json_encode(S::mapping(), JSON_THROW_ON_ERROR)])->assertCreated();
    $id = $uploaded->json('id');
    $this->get('/imports/'.$id)->assertOk()->assertInertia(fn (Page $p) => $p->component('imports/Show')->where('preview.rows.0.ready', true)->where('preview.rows.1.ready', false));
    $this->assertDatabaseCount('postings', 0);
    $bad = $this->postJson('/imports/'.$id.'/approve', ['idempotency_key' => 'synthetic-http-invalid-selection', 'selection' => ['1', '2']])->assertUnprocessable()->assertJsonPath('definitive_no_write', true);
    $this->assertDatabaseCount('postings', 0);
    $payload = ['idempotency_key' => 'synthetic-http-approval', 'selection' => ['1']];
    $approved = $this->postJson('/imports/'.$id.'/approve', $payload)->assertCreated();
    $this->postJson('/imports/'.$id.'/approve', $payload)->assertCreated()->assertJsonPath('id', $approved->json('id'));
    $this->getJson('/imports/requests/synthetic-http-approval')->assertOk()->assertJsonPath('committed', true);
    $this->get('/imports/receipts/'.$approved->json('id'))->assertOk()->assertInertia(fn (Page $p) => $p->component('imports/Receipt')->where('receipt.outcomes.0.type', 'created'));
    $csv = $this->get('/imports/'.$id.'/export')->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff')->assertDownload('halalos-statement-v1.csv');
    expect($csv->getContent())->toContain("'=SUM(1+1)")->and($csv->headers->get('Cache-Control'))->toContain('private', 'no-store');
    $this->post('/imports', ['description' => 'SYNTHETIC-PRIVATE-NO-FLASH'])->assertUnprocessable();
    expect(json_encode(session()->all(), JSON_THROW_ON_ERROR))->not->toContain('SYNTHETIC-PRIVATE-NO-FLASH', '=SUM(1+1)', 'SYNTHETIC-HTTP');
    expect(DB::table('statement_sources')->value('source_base64'))->toBe(base64_encode($bytes));
    $this->assertDatabaseCount('journal_entries', 1);
});

test('foreign owners and admins cannot read approve revise correct or export another import', function (bool $admin) {
    $snapshot = S::preview($this->owner, $this->bank, [[]]);
    $receipt = app(StatementImports::class)->approve($this->owner, (string) $snapshot->id, 'synthetic-private-import', ['1']);
    $foreign = User::factory()->create(['is_admin' => $admin]);
    $this->actingAs($foreign);
    foreach (['/imports/'.$snapshot->id, '/imports/'.$snapshot->id.'/export', '/imports/receipts/'.$receipt->id, '/imports/requests/synthetic-private-import'] as $path) {
        $this->getJson($path)->assertNotFound();
    }
    $this->postJson('/imports/'.$snapshot->id.'/approve', ['idempotency_key' => 'synthetic-foreign-approve', 'selection' => ['1']])->assertNotFound();
    $this->postJson('/imports/'.$snapshot->id.'/revise', ['resolutions' => []])->assertNotFound();
    $this->postJson('/imports/receipts/'.$receipt->id.'/1/correct', ['idempotency_key' => 'synthetic-foreign-correction', 'reason' => 'Foreign denial'])->assertNotFound();
    $this->post('/imports', ['file' => UploadedFile::fake()->createWithContent('statement.csv', S::bytes([[]])), 'namespace' => 'Foreign source', 'account_id' => (string) $this->bank->id, 'adapter_name' => 'Foreign mapping', 'mapping' => json_encode(S::mapping(), JSON_THROW_ON_ERROR)])->assertNotFound();
    $this->assertDatabaseCount('journal_entries', 1);
    $this->assertDatabaseCount('statement_sources', 1);
})->with([false, true]);

test('unsupported files malformed mappings unknown selectors and altered approval requests never commit', function () {
    $this->actingAs($this->owner);
    $base = ['namespace' => 'Synthetic invalid input', 'account_id' => (string) $this->bank->id, 'adapter_name' => 'Synthetic mapping', 'mapping' => json_encode(S::mapping(), JSON_THROW_ON_ERROR)];
    foreach ([['statement.php', S::bytes([[]])], ['statement.csv', "<html>,b,c,d\n1,2,3,4"], ['statement.csv', str_repeat('x', 262145)], ['statement.csv', "date,type,amount,description\n\"bad"]] as [$name, $bytes]) {
        $this->post('/imports', [...$base, 'file' => UploadedFile::fake()->createWithContent($name, $bytes)])->assertUnprocessable();
    }
    $file = fn () => UploadedFile::fake()->createWithContent('statement.csv', S::bytes([[]]));
    $this->post('/imports', [...$base, 'file' => $file(), 'mapping' => 'null'])->assertUnprocessable();
    $this->post('/imports?owner_id='.$this->owner->id, [...$base, 'file' => $file()])->assertUnprocessable();
    $this->post('/imports', [...$base, 'file' => $file(), 'owner_id' => (string) $this->owner->id])->assertUnprocessable();
    $this->assertDatabaseCount('statement_sources', 0);
    $s = S::preview($this->owner, $this->bank, [[]]);
    foreach ([[], ['1', '1'], ['999'], [1]] as $selection) {
        $this->postJson('/imports/'.$s->id.'/approve', ['idempotency_key' => 'synthetic-altered-selection', 'selection' => $selection])->assertUnprocessable();
    }
    $this->get('/imports/'.$s->id.'/export?owner_id='.$this->owner->id)->assertBadRequest();
    $this->assertDatabaseCount('postings', 0);
});

test('HTTP immutable resolutions and linked corrections expose original and replacement receipts', function () {
    $s = S::preview($this->owner, $this->bank, [['type' => 'unknown']]);
    $this->actingAs($this->owner)->postJson('/imports/'.$s->id.'/revise', ['resolutions' => ['1' => ['type' => 'income']]])->assertCreated();
    $this->assertDatabaseCount('statement_snapshots', 2);
    $replacement = DB::table('statement_snapshots')->max('id');
    $r = $this->postJson('/imports/'.$replacement.'/approve', ['idempotency_key' => 'synthetic-http-resolved', 'selection' => ['1']])->assertCreated();
    $corrected = $this->postJson('/imports/receipts/'.$r->json('id').'/1/correct', ['idempotency_key' => 'synthetic-http-cancelled', 'reason' => 'Synthetic reversed source'])->assertCreated();
    $this->get('/imports/receipts/'.$corrected->json('id'))->assertOk()->assertInertia(fn (Page $p) => $p->component('imports/Receipt')->where('receipt.outcomes.0.type', 'cancelled')->where('receipt.corrects_receipt_id', $r->json('id')));
    $this->assertDatabaseCount('journal_entries', 2);
});

test('import rate limit remains bounded per owner without consuming password-change quota', function () {
    $this->actingAs($this->owner);
    for ($i = 0; $i < 20; $i++) {
        $this->post('/imports/inspect', ['file' => UploadedFile::fake()->createWithContent('statement.csv', S::bytes([[]])), 'delimiter' => ','])->assertOk();
    }
    $this->post('/imports/inspect', ['file' => UploadedFile::fake()->createWithContent('statement.csv', S::bytes([[]])), 'delimiter' => ','])->assertTooManyRequests();
    $this->put('/settings/password', ['current_password' => 'password', 'password' => 'Synthetic-new-password-57', 'password_confirmation' => 'Synthetic-new-password-57'])->assertRedirect();
    $foreign = User::factory()->create();
    $this->flushSession();
    $this->actingAs($foreign)->post('/imports/inspect', ['file' => UploadedFile::fake()->createWithContent('statement.csv', S::bytes([[]])), 'delimiter' => ','])->assertOk();
    $this->assertDatabaseCount('statement_sources', 0);
});

test('every retained import write shares the owner throttle without blocking receipt reads', function (string $route) {
    $s = S::preview($this->owner, $this->bank, [[]]);
    $r = app(StatementImports::class)->approve($this->owner, (string) $s->id, 'synthetic-throttle-receipt', ['1']);
    $this->actingAs($this->owner);
    for ($i = 0; $i < 20; $i++) {
        $this->post('/imports/inspect', ['file' => UploadedFile::fake()->createWithContent('statement.csv', S::bytes([[]])), 'delimiter' => ','])->assertOk();
    }
    $path = match ($route) {
        'revise' => '/imports/'.$s->id.'/revise',
        'approve' => '/imports/'.$s->id.'/approve',
        'correct' => '/imports/receipts/'.$r->id.'/1/correct',
    };
    $this->postJson($path, [])->assertTooManyRequests();
    $this->getJson('/imports/requests/synthetic-throttle-receipt')->assertOk()->assertJsonPath('committed', true);
    $this->get('/imports/receipts/'.$r->id)->assertOk();
    $this->assertDatabaseCount('journal_entries', 1);
})->with(['revise', 'approve', 'correct']);
