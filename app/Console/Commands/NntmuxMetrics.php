<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Metrics\NntmuxPrometheusMetrics;
use Illuminate\Console\Command;

class NntmuxMetrics extends Command
{
    protected $signature = 'nntmux:metrics
                            {--serve : Serve /metrics over HTTP}
                            {--host=0.0.0.0 : HTTP listen host}
                            {--port=9100 : HTTP listen port}';

    protected $description = 'Export NNTmux operational metrics in Prometheus text format';

    public function handle(NntmuxPrometheusMetrics $metrics): int
    {
        if (! (bool) $this->option('serve')) {
            $this->output->write($metrics->render());

            return self::SUCCESS;
        }

        return $this->serve($metrics);
    }

    private function serve(NntmuxPrometheusMetrics $metrics): int
    {
        $host = (string) $this->option('host');
        $port = max(1, (int) $this->option('port'));
        $server = @stream_socket_server("tcp://{$host}:{$port}", $errno, $errstr);

        if ($server === false) {
            $this->error("Unable to listen on {$host}:{$port}: {$errstr} ({$errno})");

            return self::FAILURE;
        }

        $this->info("Serving NNTmux metrics on {$host}:{$port}");

        while (true) {
            $client = @stream_socket_accept($server, 30);
            if ($client === false) {
                continue;
            }

            $request = fgets($client) ?: '';
            while (($line = fgets($client)) !== false && trim($line) !== '') {
                // Drain headers.
            }

            $path = explode(' ', trim($request))[1] ?? '/';
            if ($path !== '/metrics') {
                $body = "not found\n";
                fwrite($client, "HTTP/1.1 404 Not Found\r\nContent-Type: text/plain\r\nContent-Length: ".strlen($body)."\r\nConnection: close\r\n\r\n{$body}");
                fclose($client);

                continue;
            }

            $body = $metrics->render();
            fwrite($client, "HTTP/1.1 200 OK\r\nContent-Type: text/plain; version=0.0.4\r\nContent-Length: ".strlen($body)."\r\nConnection: close\r\n\r\n{$body}");
            fclose($client);
        }
    }
}
