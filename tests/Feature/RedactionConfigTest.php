<?php

declare(strict_types=1);

use Cbox\Telemetry\Support\Redactor;

/**
 * Redaction as the package actually ships it.
 *
 * The unit tests build a Redactor from a hand-written array, which is how a
 * whole security fix once shipped inert: fromConfig() prefers the configured
 * `patterns` over defaultPatterns(), the shipped config hard-copied an older
 * list, and every test passed while no real installation redacted anything
 * new. These go through the merged config, so the wiring is covered too.
 */
it('redacts query credentials through the merged package configuration', function () {
    $redactor = Redactor::fromConfig(config('telemetry.redaction'));

    expect($redactor->value('url.query', 'api_token=SECRET&page=2'))
        ->toBe('api_token=[REDACTED]&page=2')
        ->and($redactor->value('http.request.header.referer', 'https://idp.test/cb?code=SECRET'))
        ->toBe('https://idp.test/cb?code=[REDACTED]')
        ->and($redactor->value('exception.message', 'GET https://x.test/?token=SECRET failed'))
        ->toBe('GET https://x.test/?token=[REDACTED] failed');
});

it('leaves ordinary business parameters alone through that same configuration', function () {
    $redactor = Redactor::fromConfig(config('telemetry.redaction'));

    foreach (['postal_code=2100', 'country_code=DK', 'sort_key=name', 'order_state=paid', 'token_count=42'] as $value) {
        expect($redactor->value('url.query', $value))->toBe($value);
    }

    // A whole attribute value that merely looks like a pair is not a query.
    expect($redactor->value('cache.key', 'key=abc'))->toBe('key=abc');
});
