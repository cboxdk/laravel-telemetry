<?php

declare(strict_types=1);

use Cbox\Telemetry\Support\HttpShape;

it('replaces id-like path segments with a placeholder', function (string $path, string $template) {
    expect(HttpShape::template($path))->toBe($template);
})->with([
    'number' => ['/users/17/posts', '/users/{id}/posts'],
    'date' => ['/reports/2024-01-31', '/reports/{id}'],
    'uuid' => ['/orders/3f2b8c1e-9a4d-4e6f-8b1a-2c3d4e5f6a7b', '/orders/{id}'],
    'uppercase uuid' => ['/orders/3F2B8C1E-9A4D-4E6F-8B1A-2C3D4E5F6A7B', '/orders/{id}'],
    'ulid' => ['/events/01ARZ3NDEKTSV4RRFFQ69G5FAV', '/events/{id}'],
    'sha1' => ['/commits/da39a3ee5e6b4b0d3255bfef95601890afd80709', '/commits/{id}'],
    'object id' => ['/docs/507f1f77bcf86cd799439011', '/docs/{id}'],
    'stripe id' => ['/v1/customers/cus_NffrFeUfNV2Hib', '/v1/customers/{id}'],
    'mixed token' => ['/files/a8Kd93jfLq0zPw7xYt', '/files/{id}'],
    'email' => ['/users/alice@example.test/settings', '/users/{id}/settings'],
    'encoded email' => ['/users/alice%40example.test', '/users/{id}'],
    'id with extension' => ['/orders/17.json', '/orders/{id}.json'],
    'several ids' => ['/repos/42/issues/7/comments', '/repos/{id}/issues/{id}/comments'],
    'trailing slash' => ['/users/17/', '/users/{id}/'],
]);

it('leaves names alone, even when they hold digits', function (string $path) {
    expect(HttpShape::template($path))->toBe($path);
})->with([
    '/v1/charges',
    '/api/v2/users',
    '/oauth2/token',
    '/user_settings',
    '/feed.json',
    '/s3/buckets',
    '/deadbeef',
]);

it('treats an empty path as the root', function () {
    expect(HttpShape::template(''))->toBe('/')
        ->and(HttpShape::template('/'))->toBe('/');
});

it('drops query values and keeps the sorted parameter names', function () {
    expect(HttpShape::normalize('get', 'API.Example.test', '/users/17', 'expand=team&fields=a,b&expand=org'))
        ->toBe('GET api.example.test/users/{id}?expand&fields');
});

it('hashes calls with different ids and values to one shape', function () {
    $a = HttpShape::hash('GET', 'api.example.test', '/users/17', 'include=posts');
    $b = HttpShape::hash('GET', 'api.example.test', '/users/42', 'include=comments');

    expect($a)->toHaveLength(16)->toBe($b);
});

it('keeps different endpoints, methods, hosts and parameter names apart', function () {
    $base = HttpShape::hash('GET', 'api.example.test', '/users/17');

    expect(HttpShape::hash('GET', 'api.example.test', '/teams/17'))->not->toBe($base)
        ->and(HttpShape::hash('DELETE', 'api.example.test', '/users/17'))->not->toBe($base)
        ->and(HttpShape::hash('GET', 'other.example.test', '/users/17'))->not->toBe($base)
        ->and(HttpShape::hash('GET', 'api.example.test:8443', '/users/17'))->not->toBe($base)
        ->and(HttpShape::hash('GET', 'api.example.test', '/users/17', 'expand=x'))->not->toBe($base);
});

it('tells reads from writes', function () {
    expect(HttpShape::isRead('GET'))->toBeTrue()
        ->and(HttpShape::isRead('head'))->toBeTrue()
        ->and(HttpShape::isRead('POST'))->toBeFalse()
        ->and(HttpShape::isRead('PUT'))->toBeFalse()
        ->and(HttpShape::isRead('DELETE'))->toBeFalse();
});

it('recognises pagination', function (string $path, string $query) {
    expect(HttpShape::isPagination($path, $query))->toBeTrue();
})->with([
    'page' => ['/users', 'page=2'],
    'cursor' => ['/users', 'cursor=abc&limit=50'],
    'offset' => ['/users', 'limit=50&offset=100'],
    'json:api page' => ['/users', 'page%5Bnumber%5D=2'],
    'json:api bracket' => ['/users', 'page[after]=abc'],
    'stripe' => ['/v1/charges', 'starting_after=ch_123'],
    'google' => ['/drive/v3/files', 'pageToken=xyz'],
    'twitter' => ['/statuses', 'max_id=12345'],
    'case' => ['/users', 'Page=2'],
    'path' => ['/posts/page/3', ''],
]);

it('does not call a plain lookup pagination', function (string $path, string $query) {
    expect(HttpShape::isPagination($path, $query))->toBeFalse();
})->with([
    'id path' => ['/users/17', ''],
    'filter' => ['/users', 'id=17'],
    'limit alone' => ['/users', 'limit=10'],
    'page as a resource' => ['/pages/17', ''],
    'pager-ish name' => ['/users', 'pages_count=1'],
]);
