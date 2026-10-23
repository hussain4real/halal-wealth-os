<?php

declare(strict_types=1);

namespace App\Domain\Integrations;

interface FxProvider
{
    public function fx(MarketFeed $feed): MarketSample;
}
