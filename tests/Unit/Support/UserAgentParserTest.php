<?php

declare(strict_types=1);

use Cbox\Telemetry\Support\UserAgentParser;

it('returns nothing for an empty UA', function () {
    expect(UserAgentParser::parse(null))->toBe([])
        ->and(UserAgentParser::parse(''))->toBe([]);
});

it('parses common desktop browsers/OS', function () {
    $chrome = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36';
    expect(UserAgentParser::parse($chrome))->toMatchArray([
        'user_agent.name' => 'Chrome', 'os.name' => 'Windows', 'device.type' => 'desktop',
    ]);

    $safariMac = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Safari/605.1.15';
    expect(UserAgentParser::parse($safariMac))->toMatchArray([
        'user_agent.name' => 'Safari', 'os.name' => 'macOS', 'device.type' => 'desktop',
    ]);
});

it('distinguishes Edge from Chrome and mobile from desktop', function () {
    $edge = 'Mozilla/5.0 (Windows NT 10.0) AppleWebKit/537.36 Chrome/120.0 Safari/537.36 Edg/120.0';
    expect(UserAgentParser::parse($edge)['user_agent.name'])->toBe('Edge');

    $iphone = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1';
    expect(UserAgentParser::parse($iphone))->toMatchArray([
        'user_agent.name' => 'Safari', 'os.name' => 'iOS', 'device.type' => 'mobile',
    ]);

    $ipad = 'Mozilla/5.0 (iPad; CPU OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Version/17.0 Safari/604.1';
    expect(UserAgentParser::parse($ipad)['device.type'])->toBe('tablet');
});

it('flags bots', function () {
    $bot = 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)';
    expect(UserAgentParser::parse($bot))->toMatchArray([
        'user_agent.name' => 'Bot', 'device.type' => 'bot',
    ]);
});
