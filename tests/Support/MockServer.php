<?php

declare(strict_types=1);

namespace PdfConversionApi\Tests\Support;

/**
 * Runs conformance/mock-server.mjs on a random port for the length of a test class.
 */
final class MockServer
{
    /** @var resource */
    private $process;

    /** @var resource */
    private $stdout;

    public readonly string $url;

    /**
     * @param resource $process
     * @param resource $stdout
     */
    private function __construct($process, $stdout, string $url)
    {
        $this->process = $process;
        $this->stdout = $stdout;
        $this->url = $url;
    }

    public static function start(string $conformanceDir): self
    {
        $node = getenv('NODE') ?: 'node';
        $pipes = [];
        $process = proc_open(
            [$node, $conformanceDir . '/mock-server.mjs', '--port', '0'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        if (!is_resource($process)) {
            throw new \RuntimeException('could not start the mock server: is Node.js 18+ installed?');
        }
        $stdout = $pipes[1];
        fclose($pipes[0]);
        stream_set_blocking($pipes[2], false);
        $read = [$stdout];
        $write = $except = null;
        $line = stream_select($read, $write, $except, 15) === 1 ? fgets($stdout) : false;
        if (!is_string($line) || preg_match('#^LISTENING (http://\S+)#', $line, $match) !== 1) {
            $stderr = (string) stream_get_contents($pipes[2]);
            proc_terminate($process);
            throw new \RuntimeException('the mock server did not start: ' . trim($stderr));
        }

        return new self($process, $stdout, $match[1]);
    }

    /** How many requests carried this MockId. */
    public function attempts(string $mockId): int
    {
        $body = file_get_contents($this->url . '/__mock/attempts/' . rawurlencode($mockId));
        $json = json_decode((string) $body, true, 8, JSON_THROW_ON_ERROR);

        return is_array($json) && is_int($json['attempts'] ?? null) ? $json['attempts'] : -1;
    }

    public function stop(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            fclose($this->stdout);
            proc_close($this->process);
        }
    }
}
