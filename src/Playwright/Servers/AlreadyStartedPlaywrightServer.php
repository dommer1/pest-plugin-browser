<?php

declare(strict_types=1);

namespace Pest\Browser\Playwright\Servers;

use JsonException;
use Pest\Browser\Contracts\PlaywrightServer;
use Pest\Browser\Support\PackageJsonDirectory;
use RuntimeException;
use Throwable;

/**
 * @internal
 */
final class AlreadyStartedPlaywrightServer implements PlaywrightServer
{
    /**
     * After how many seconds of waiting it is checked that Playwright is installed.
     */
    private const int INSTALLATION_CHECK_AFTER = 3;

    /**
     * How many seconds to wait for the server at most.
     */
    private const int TIMEOUT = 60;

    /**
     * Whether the server accepted a connection.
     */
    private bool $ready = false;

    /**
     * The reason the server is not available, once waiting for it failed.
     */
    private ?Throwable $failure = null;

    /**
     * Creates a new already started playwright server instance.
     */
    public function __construct(
        public readonly string $host,
        public readonly int $port,
    ) {
        //
    }

    /**
     * Creates a new instance of the Playwright server with the persisted host and port.
     *
     * @throws JsonException
     */
    public static function fromPersisted(): self
    {
        $path = self::path();

        $path = file_get_contents($path);

        if ($path === false) {
            throw new RuntimeException('Could not read Playwright server data from file.');
        }

        // @phpstan-ignore-next-line
        ['host' => $host, 'port' => $port] = json_decode($path, true, 512, JSON_THROW_ON_ERROR);

        assert(is_string($host) && is_numeric($port), 'Invalid Playwright server data persisted.');

        return new self($host, (int) $port);
    }

    /**
     * Persists the Playwright server instance with the given host and port
     * as already started; this is useful for scenarios where the server is
     * already running and you want to connect to it as if it were started.
     *
     * @throws JsonException
     */
    public static function persist(string $host, int $port): void
    {
        $data = [
            'host' => $host,
            'port' => $port,
        ];

        $path = self::path();

        if (! file_exists(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }

        file_put_contents($path, json_encode($data, JSON_THROW_ON_ERROR));
    }

    /**
     * Marks the Playwright server a stopped by removing the persisted state file.
     */
    public static function markAsStopped(): void
    {
        $path = self::path();

        @unlink($path);
    }

    /**
     * Waits until the server, started in the background by the parent process, accepts connections.
     */
    public function start(): void
    {
        if ($this->ready) {
            return;
        }

        if ($this->failure instanceof Throwable) {
            throw $this->failure;
        }

        try {
            $this->waitUntilAcceptingConnections();
        } catch (Throwable $e) {
            throw $this->failure = $e;
        }

        $this->ready = true;
    }

    /**
     * Stops the process if it is running.
     */
    public function stop(): void
    {
        //
    }

    /**
     * Flushes the process.
     */
    public function flush(): void
    {
        //
    }

    /**
     * Returns the URL of the process.
     */
    public function url(): string
    {
        return sprintf('%s:%d', $this->host, $this->port);
    }

    /**
     * Returns the state file of the Playwright server.
     */
    private static function path(): string
    {
        return dirname(__DIR__, 3).'/.temp/playwright-server.json';
    }

    /**
     * Waits until the server accepts connections, failing early when Playwright is not installed.
     */
    private function waitUntilAcceptingConnections(): void
    {
        $startedAt = microtime(true);
        $installationChecked = false;

        while (! $this->acceptsConnections()) {
            $waited = microtime(true) - $startedAt;

            if (! $installationChecked && $waited >= self::INSTALLATION_CHECK_AFTER) {
                PlaywrightNpmServer::ensurePlaywrightIsInstalledAndVersionIsSupported(PackageJsonDirectory::find());

                $installationChecked = true;
            }

            if ($waited >= self::TIMEOUT) {
                throw new RuntimeException(sprintf(
                    'The Playwright server at [%s] did not accept connections within %d seconds.',
                    $this->url(),
                    self::TIMEOUT,
                ));
            }

            usleep(20_000);
        }
    }

    /**
     * Determines whether the server accepts connections.
     */
    private function acceptsConnections(): bool
    {
        $socket = @fsockopen($this->host, $this->port, $errorCode, $errorMessage, 1);

        if ($socket === false) {
            return false;
        }

        fclose($socket);

        return true;
    }
}
