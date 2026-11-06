<?php

declare(strict_types=1);

namespace Cal5\Tests;

use RuntimeException;

/** PHP's built-in web server serving one course directory. */
final class Server
{
    /** @var resource|null */
    private $process;
    private string $sessionDir;
    public readonly int $port;

    public function __construct(public readonly string $docRoot)
    {
        $this->port = self::freePort();
        $this->sessionDir = $docRoot . '/../sessions';
        @mkdir($this->sessionDir, 0777, true);
        $log = $docRoot . '/../server.log';
        $command = [
            PHP_BINARY,
            '-d', 'display_errors=1',
            '-d', 'html_errors=1',
            '-d', 'error_reporting=-1',
            '-d', 'log_errors=0',
            '-d', 'session.save_path=' . $this->sessionDir,
            // no realpath cache and no OPcache, so a course can be switched (calendar -> v4 / Cal5)
            // while the server runs; a real server needs a reload after switching (see the migration notes)
            '-d', 'realpath_cache_size=0',
            '-d', 'opcache.enable=0',
            '-S', '127.0.0.1:' . $this->port,
            '-t', $docRoot,
        ];
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, $docRoot);
        if (!is_resource($process)) {
            throw new RuntimeException('Could not start php -S');
        }
        $this->process = $process;
        for ($i = 0; $i < 100; $i++) {
            $socket = @fsockopen('127.0.0.1', $this->port);
            if ($socket !== false) {
                fclose($socket);
                return;
            }
            usleep(50000);
        }
        $this->stop();
        throw new RuntimeException('php -S did not start on port ' . $this->port);
    }

    public function url(string $path = ''): string
    {
        return 'http://127.0.0.1:' . $this->port . '/' . ltrim($path, '/');
    }

    public function stop(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
        }
        $this->process = null;
    }

    public function __destruct()
    {
        $this->stop();
    }

    private static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        if ($socket === false) {
            throw new RuntimeException("No free port: $error");
        }
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);
        return (int) substr($name, (int) strrpos($name, ':') + 1);
    }
}
