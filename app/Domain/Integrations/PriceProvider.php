<?php

declare(strict_types=1);

namespace App\Domain\Integrations;

interface PriceProvider
{
    public function price(MarketFeed $feed): MarketSample;
}
