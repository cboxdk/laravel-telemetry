<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Tests\Support;

use RuntimeException;

/**
 * A real HTTP server standing in for an OTLP endpoint.
 *
 * The bug this package's tests exist to prevent — a rejected batch
 * reported as a success — lives in what the code does with a genuine
 * response, so the tests use a genuine one: PHP's built-in server on
 * loopback, answering with a configured status and body, and recording
 * what it received (including whether the body arrived gzipped).
 *
 * A double for the transport could not have caught the original bug; a
 * double that returns "rejected" only proves the double.
 */
final class StubOtlpServer
{
    /**
     * @param  resource  $process
     */
    private function __construct(
        private $process,
        private readonly string $directory,
        public readonly int $port,
    ) {}

    /**
     * @param  int  $status  the status every request is answered with
     * @param  string  $body  the response body, e.g. a collector's JSON error
     */
    public static function start(int $status = 200, string $body = '{}'): self
    {
        $directory = sys_get_temp_dir().'/telemetry-otlp-stub-'.bin2hex(random_bytes(6));

        if (! mkdir($directory) && ! is_dir($directory)) {
            throw new RuntimeException("Could not create the stub server directory [{$directory}].");
        }

        file_put_contents($directory.'/response.json', json_encode(['status' => $status, 'body' => $body], JSON_THROW_ON_ERROR));
        file_put_contents($directory.'/router.php', self::router());

        $port = self::freePort();

        $process = proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:{$port}", '-t', $directory, $directory.'/router.php'],
            [1 => ['file', $directory.'/stdout.log', 'a'], 2 => ['file', $directory.'/stderr.log', 'a']],
            $pipes,
        );

        if (! is_resource($process)) {
            throw new RuntimeException('Could not start the stub OTLP server.');
        }

        $server = new self($process, $directory, $port);
        $server->awaitReady();

        return $server;
    }

    public function url(): string
    {
        return "http://127.0.0.1:{$this->port}";
    }

    /**
     * Every request the server saw, oldest first.
     *
     * @return list<array{path: string, encoding: string, bytes: int, body: string}>
     */
    public function requests(): array
    {
        $log = $this->directory.'/requests.jsonl';

        if (! is_file($log)) {
            return [];
        }

        $requests = [];

        foreach (array_filter(explode("\n", (string) file_get_contents($log))) as $line) {
            /** @var array{path: string, encoding: string, bytes: int, body: string} $request */
            $request = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            $requests[] = $request;
        }

        return $requests;
    }

    public function stop(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
        }

        foreach (glob($this->directory.'/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->directory);
    }

    /**
     * A port nobody is listening on right now. The window between closing
     * this socket and the server binding it is a theoretical race and a
     * practical non-issue on loopback.
     */
    private static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);

        if ($socket === false) {
            throw new RuntimeException("Could not reserve a port for the stub OTLP server: {$error}");
        }

        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr($name, (int) strrpos($name, ':') + 1);
    }

    private function awaitReady(): void
    {
        for ($attempt = 0; $attempt < 200; $attempt++) {
            // A refused connection while the server is still booting is
            // expected, not a test warning — PHPUnit's error handler
            // ignores @-suppression, so silence it explicitly.
            set_error_handler(static fn (): bool => true);

            try {
                $connection = fsockopen('127.0.0.1', $this->port, $errno, $error, 0.1);
            } finally {
                restore_error_handler();
            }

            if (is_resource($connection)) {
                fclose($connection);

                return;
            }

            usleep(25_000);
        }

        $this->stop();

        throw new RuntimeException("The stub OTLP server never came up on port {$this->port}.");
    }

    private static function router(): string
    {
        return <<<'PHP'
        <?php

        declare(strict_types=1);

        $response = json_decode((string) file_get_contents(__DIR__.'/response.json'), true);
        $raw = (string) file_get_contents('php://input');
        $encoding = strtolower((string) ($_SERVER['HTTP_CONTENT_ENCODING'] ?? ''));

        file_put_contents(
            __DIR__.'/requests.jsonl',
            json_encode([
                'path' => (string) ($_SERVER['REQUEST_URI'] ?? ''),
                'encoding' => $encoding,
                'bytes' => strlen($raw),
                'body' => $encoding === 'gzip' ? (string) @gzdecode($raw) : $raw,
            ])."\n",
            FILE_APPEND | LOCK_EX,
        );

        http_response_code((int) $response['status']);
        header('Content-Type: application/json');
        print $response['body'];
        PHP;
    }
}
