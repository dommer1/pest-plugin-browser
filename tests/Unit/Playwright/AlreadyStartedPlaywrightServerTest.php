<?php

declare(strict_types=1);

use Pest\Browser\Playwright\Servers\AlreadyStartedPlaywrightServer;
use Pest\Browser\Support\Port;
use Symfony\Component\Process\Process;

test('waits until the server started by the parent process accepts connections', function (): void {
    $port = Port::find();

    $listener = new Process([PHP_BINARY, '-r', sprintf(
        'usleep(500000); $server = stream_socket_server("tcp://127.0.0.1:%d"); sleep(10);',
        $port,
    )]);

    $listener->start();

    try {
        new AlreadyStartedPlaywrightServer('127.0.0.1', $port)->start();

        $connection = @fsockopen('127.0.0.1', $port);

        expect($connection)->not->toBeFalse();
    } finally {
        $listener->stop(0);
    }
});

test('does not wait again once the server accepted a connection', function (): void {
    $server = stream_socket_server('tcp://127.0.0.1:0');

    expect($server)->not->toBeFalse();

    assert(is_resource($server));

    $address = (string) stream_socket_get_name($server, false);
    $port = (int) mb_substr($address, (int) mb_strrpos($address, ':') + 1);

    $playwright = new AlreadyStartedPlaywrightServer('127.0.0.1', $port);
    $playwright->start();

    fclose($server);

    $playwright->start();

    expect($playwright->url())->toBe("127.0.0.1:{$port}");
});
