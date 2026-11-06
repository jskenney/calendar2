<?php

declare(strict_types=1);

namespace Cal5\Compare;

use RuntimeException;

/** PHP's built-in web server serving a course directory (bin/cal5 compare). */
final class PhpServer
{
    /** @var resource|null */
    private $process;
    public readonly int $port;

    /** @param bool $showErrors print PHP warnings into the pages (Cal5: so they are found) or only log them (v4's known warnings) */
    public function __construct(public readonly string $docRoot, string $workDir, bool $showErrors = true)
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        if ($socket === false) {
            throw new RuntimeException("no free port: $error");
        }
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);
        $this->port = (int) substr($name, (int) strrpos($name, ':') + 1);
        @mkdir($workDir . '/sessions', 0700, true);
        $command = [PHP_BINARY, '-d', 'display_errors=' . ($showErrors ? '1' : '0'), '-d', 'html_errors=1', '-d', 'error_reporting=-1', '-d', 'log_errors=0',
            '-d', 'session.save_path=' . $workDir . '/sessions', '-d', 'opcache.enable=0',
            '-S', '127.0.0.1:' . $this->port, '-t', $docRoot];
        $log = $workDir . '/server.log';
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, $docRoot);
        if (!is_resource($process)) {
            throw new RuntimeException('could not start php -S');
        }
        $this->process = $process;
        for ($i = 0; $i < 100; $i++) {
            $connection = @fsockopen('127.0.0.1', $this->port);
            if ($connection !== false) {
                fclose($connection);
                return;
            }
            usleep(50000);
        }
        $this->stop();
        throw new RuntimeException('php -S did not start');
    }

    public function url(string $path): string
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
}
