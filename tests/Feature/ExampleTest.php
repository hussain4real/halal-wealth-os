<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);
test('test_returns_a_successful_response', function () {
    $response = $this->get(route('home'));
    $response->assertOk();
});
