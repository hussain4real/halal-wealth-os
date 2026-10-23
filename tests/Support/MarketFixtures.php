<?php

namespace Tests\Support;

use App\Domain\Integrations\MarketFeed;
use App\Domain\Integrations\MarketRefresh;
use App\Domain\Portfolio\PriceObservationService;
use App\Domain\Shared\Money\Currency;
use App\Models\User;
use Illuminate\Support\Str;
use stdClass;

final class MarketFixtures
{
    /** @return array{mapping:stdClass,account:stdClass,asset:stdClass,input:array<string,mixed>,feed:MarketFeed} */
    public static function price(User $owner, array $changes = []): array
    {
        $account = PortfolioFixtures::link($owner, PortfolioFixtures::account($owner, Currency::USD));
        PortfolioFixtures::mode($owner, $account);
        $asset = PortfolioFixtures::asset($owner, ['currency' => 'USD', 'identifier' => 'SYNTH', 'market' => 'Synthetic XNAS instrument']);
        $input = [...['kind' => 'price', 'symbol' => 'SYNTH', 'currency' => 'USD', 'mic_code' => 'XNAS', 'exchange_timezone' => 'America/New_York',
            'portfolio_account_id' => (string) $account->id, 'asset_id' => (string) $asset->id,
            'context' => app(PriceObservationService::class)->context($owner, (string) $account->id), 'raw_confirmed' => '1', 'enabled' => '1'], ...$changes];
        $mapping = app(MarketRefresh::class)->map($owner, (string) Str::uuid(), $input);

        return ['mapping' => $mapping, 'account' => $account, 'asset' => $asset, 'input' => $input, 'feed' => MarketFeed::price($input['symbol'], 'USD', 'XNAS', 'America/New_York')];
    }

    /** @return array<string,string> */
    public static function fxInput(string $base = 'USD', string $quote = 'QAR'): array
    {
        return ['kind' => 'fx', 'base' => $base, 'quote' => $quote, 'enabled' => '1'];
    }

    public static function priceBody(string $date, string $value = '12.123456789012', array $meta = []): string
    {
        return json_encode(['meta' => [...['symbol' => 'SYNTH', 'currency' => 'USD', 'mic_code' => 'XNAS', 'exchange_timezone' => 'America/New_York', 'exchange' => 'NASDAQ', 'type' => 'Common Stock', 'interval' => '1day'], ...$meta],
            'values' => [['datetime' => $date, 'close' => $value]], 'status' => 'ok'], JSON_THROW_ON_ERROR);
    }

    public static function fxBody(string $symbol = 'USD/QAR', string $value = '3.64', ?int $timestamp = null): string
    {
        return '{"symbol":"'.$symbol.'","rate":'.$value.',"timestamp":'.($timestamp ?? now('UTC')->subMinute()->getTimestamp()).'}';
    }
}
