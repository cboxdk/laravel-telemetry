<?php

declare(strict_types=1);

use Cbox\Telemetry\Support\PersonalData;
use Cbox\Telemetry\Support\Redactor;

/**
 * A defence-in-depth control, not compliance. It catches identifiers
 * that leaked in by accident — an email in an exception message, a card
 * number in a SQL string, a CPR in a URL path — which is how personal
 * data actually reaches an observability pipeline.
 *
 * Precision is the feature. A detector that eats order ids gets
 * switched off within a day, which is worse than never having shipped.
 */
function scrubPii(string $value, ?array $detectors = null): string
{
    $redactor = Redactor::fromConfig([
        'pii' => $detectors === null ? true : ['detectors' => $detectors],
    ] + config('telemetry.redaction'));

    return $redactor->value('log.context.message', $value);
}

it('is off unless asked for', function () {
    $redactor = Redactor::fromConfig(config('telemetry.redaction'));

    expect($redactor->value('log.context.message', 'wrote to alice@example.com'))
        ->toBe('wrote to alice@example.com');
});

it('finds identifiers wherever they leaked', function (string $text, string $identifier) {
    expect(scrubPii($text))->not->toContain($identifier);
})->with([
    'email in an exception' => ['SQLSTATE[23000]: Duplicate entry \'alice@example.com\' for key \'users_email_unique\'', 'alice@example.com'],
    'card in a query' => ['select * from cards where pan = 4111111111111111', '4111111111111111'],
    'card with spaces' => ['charged 4111 1111 1111 1111 for order 9', '4111 1111 1111 1111'],
    'iban in a payout' => ['payout to DK5000400440116243 failed', 'DK5000400440116243'],
    'ssn in a payload' => ['applicant 123-45-6789 rejected', '123-45-6789'],
    'cpr in a url path' => ['/patients/010203-1234/journal', '010203-1234'],
]);

it('leaves the identifiers an application is made of', function (string $text) {
    // Without a checksum every one of these is a false positive, and
    // the first person to see an order id redacted turns the whole
    // thing off.
    expect(scrubPii($text))->toBe($text);
})->with([
    'a long order number' => 'order 4111111111111112 shipped',
    'a primary key' => 'select * from orders where id = 1234567890123456',
    'an SSN placeholder range' => 'ticket 000-00-0000 is a fixture',
    'a reference that looks like an IBAN' => 'reference XX00ABCDEFGHIJKLMNOP failed',
    'a version string' => 'upgraded to 10.4.32-MariaDB',
    'a timestamp' => 'at 2026-09-26T12:00:00Z',
]);

it('keeps IP addresses unless they are asked for', function () {
    // An IP is personal data under the GDPR and also the only way to
    // find the one host that is broken. That trade is the operator's,
    // not this package's.
    expect(scrubPii('upstream 203.0.113.7 refused'))->toContain('203.0.113.7')
        ->and(scrubPii('upstream 203.0.113.7 refused', ['ip']))->not->toContain('203.0.113.7');
});

it('takes only the detectors it was given', function () {
    $text = 'alice@example.com paid with 4111111111111111';

    expect(scrubPii($text, ['email']))
        ->not->toContain('alice@example.com')
        ->toContain('4111111111111111');
});

it('ignores a detector name it does not know rather than throwing', function () {
    // A typo in a config file must never break telemetry.
    expect(scrubPii('alice@example.com', ['email', 'nonsense']))->not->toContain('alice@example.com');
});

it('validates the checksums it claims to', function () {
    expect(PersonalData::availableDetectors())->toContain('credit_card')
        // Luhn-valid test PAN vs the same number with one digit changed.
        ->and(scrubPii('4111111111111111'))->not->toContain('4111111111111111')
        ->and(scrubPii('4111111111111112'))->toContain('4111111111111112')
        // IBAN mod-97.
        ->and(scrubPii('DK5000400440116243'))->not->toContain('DK5000400440116243')
        ->and(scrubPii('DK5000400440116244'))->toContain('DK5000400440116244');
});

it('reports which detectors are running, including none', function () {
    // The closest thing this package has to evidence of a control, and
    // the answer an auditor asks for as often as a list. Also the only
    // way an operator who set `pii` in a config the package never read
    // finds out.
    $this->artisan('telemetry:doctor')
        ->expectsOutputToContain('not scrubbed');

    config()->set('telemetry.redaction.pii', ['detectors' => ['email', 'dk_cpr']]);

    $this->artisan('telemetry:doctor')
        ->expectsOutputToContain('scrubbing email, dk_cpr');
});
