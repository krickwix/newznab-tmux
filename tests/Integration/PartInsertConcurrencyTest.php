<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;

final class PartInsertConcurrencyTest extends TestCase
{
    private string $dsn;

    private string $user;

    private string $password;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dsn = (string) getenv('NNTMUX_MARIADB_CONCURRENCY_DSN');
        $this->user = (string) (getenv('NNTMUX_MARIADB_CONCURRENCY_USER') ?: 'root');
        $this->password = (string) getenv('NNTMUX_MARIADB_CONCURRENCY_PASSWORD');

        if ($this->dsn === '') {
            self::markTestSkipped('Set NNTMUX_MARIADB_CONCURRENCY_DSN to run the MariaDB concurrency regression.');
        }
        if (! function_exists('pcntl_fork')) {
            self::markTestSkipped('The pcntl extension is required for the MariaDB concurrency regression.');
        }

        $db = $this->connect();
        $database = (string) $db->query('SELECT DATABASE()')->fetchColumn();
        if (! str_ends_with($database, '_test')) {
            throw new \RuntimeException('Concurrency regression refuses to mutate a database without an _test suffix.');
        }

        $db->exec('DROP TABLE IF EXISTS nntmux_probe_parts');
        $db->exec('DROP TABLE IF EXISTS nntmux_probe_binaries');
        $db->exec('CREATE TABLE nntmux_probe_binaries (
            id BIGINT UNSIGNED NOT NULL,
            currentparts INT UNSIGNED NOT NULL DEFAULT 0,
            partsize BIGINT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB');
        $db->exec('CREATE TABLE nntmux_probe_parts (
            binaries_id BIGINT UNSIGNED NOT NULL,
            messageid VARCHAR(255) NOT NULL,
            number BIGINT UNSIGNED NOT NULL,
            partnumber INT UNSIGNED NOT NULL,
            size INT UNSIGNED NOT NULL,
            PRIMARY KEY (binaries_id, number),
            KEY ix_parts_number (number),
            CONSTRAINT fk_probe_binary FOREIGN KEY (binaries_id) REFERENCES nntmux_probe_binaries (id) ON DELETE CASCADE
        ) ENGINE=InnoDB');
        $db->exec('INSERT INTO nntmux_probe_binaries (id) VALUES (1),(2),(3),(4)');
    }

    protected function tearDown(): void
    {
        if (isset($this->dsn) && $this->dsn !== '') {
            $db = $this->connect();
            $db->exec('DROP TABLE IF EXISTS nntmux_probe_parts');
            $db->exec('DROP TABLE IF EXISTS nntmux_probe_binaries');
        }

        parent::tearDown();
    }

    public function test_read_committed_ordered_part_writes_avoid_deadlocks_and_keep_aggregates_exact(): void
    {
        $before = $this->deadlockCount();

        // Disjoint parents still target the same newly-created clustered-index
        // pages. Reverse input proves the write helper, rather than its caller,
        // establishes a consistent primary-key order.
        for ($iteration = 0; $iteration < 10; $iteration++) {
            $first = 1001 + ($iteration * 100);
            $last = $first + 99;
            $this->runConcurrently(
                function () use ($first, $last): void {
                    $this->writeParts(1, range($last, $first));
                },
                function () use ($first, $last): void {
                    $this->writeParts(2, range($last, $first));
                },
            );
        }

        // Two replays for one binary serialize on the parent X lock. The second
        // READ COMMITTED probe must see the first commit and avoid overcounting.
        $this->runConcurrently(
            function (): void {
                $this->writeParts(3, range(2200, 2001));
            },
            function (): void {
                $this->writeParts(3, range(2200, 2001));
            },
        );

        $this->writeParts(4, range(3040, 3001));
        $this->runConcurrently(
            function (): void {
                $this->writeParts(1, range(5100, 5001));
            },
            function (): void {
                $db = $this->connect();
                $db->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
                $db->beginTransaction();
                $db->query('SELECT id FROM nntmux_probe_binaries WHERE id = 4 FOR UPDATE')->fetchColumn();
                $db->exec('DELETE FROM nntmux_probe_parts WHERE binaries_id = 4');
                $db->exec('DELETE FROM nntmux_probe_binaries WHERE id = 4');
                $db->commit();
            },
        );

        self::assertSame($before, $this->deadlockCount(), 'The concurrency contract must not create an InnoDB deadlock.');

        $db = $this->connect();
        $mismatches = (int) $db->query(
            'SELECT COUNT(*) FROM nntmux_probe_binaries b
             WHERE b.currentparts <> (SELECT COUNT(*) FROM nntmux_probe_parts p WHERE p.binaries_id = b.id)
                OR b.partsize <> COALESCE((SELECT SUM(p.size) FROM nntmux_probe_parts p WHERE p.binaries_id = b.id), 0)'
        )->fetchColumn();
        self::assertSame(0, $mismatches);
        self::assertSame(0, (int) $db->query('SELECT COUNT(*) FROM nntmux_probe_binaries WHERE id = 4')->fetchColumn());
    }

    /** @param list<int> $numbers */
    private function writeParts(int $binaryId, array $numbers): void
    {
        $db = $this->connect();
        $db->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
        $db->beginTransaction();
        $lock = $db->prepare('SELECT id FROM nntmux_probe_binaries WHERE id = ? FOR UPDATE');
        $lock->execute([$binaryId]);
        if ($lock->fetchColumn() === false) {
            throw new \RuntimeException("Missing binary {$binaryId}");
        }

        sort($numbers, SORT_NUMERIC);
        foreach (array_chunk($numbers, 100) as $chunk) {
            $tuples = implode(',', array_fill(0, count($chunk), '(?,?)'));
            $bindings = [];
            foreach ($chunk as $number) {
                $bindings[] = $binaryId;
                $bindings[] = $number;
            }

            $existing = $db->prepare("SELECT binaries_id, number FROM nntmux_probe_parts WHERE (binaries_id, number) IN ({$tuples})");
            $existing->execute($bindings);
            $existingNumbers = array_fill_keys(array_map('intval', $existing->fetchAll(PDO::FETCH_COLUMN, 1)), true);
            $missing = array_values(array_filter($chunk, static fn (int $number): bool => ! isset($existingNumbers[$number])));
            if ($missing === []) {
                continue;
            }

            $update = $db->prepare('UPDATE nntmux_probe_binaries SET currentparts = currentparts + ?, partsize = partsize + ? WHERE id = ?');
            $update->execute([count($missing), count($missing) * 100, $binaryId]);

            $values = implode(',', array_fill(0, count($missing), '(?,?,?,?,?)'));
            $insertBindings = [];
            foreach ($missing as $number) {
                array_push($insertBindings, $binaryId, "<{$number}@probe>", $number, 1, 100);
            }
            $insert = $db->prepare("INSERT IGNORE INTO nntmux_probe_parts (binaries_id, messageid, number, partnumber, size) VALUES {$values}");
            $insert->execute($insertBindings);
        }

        $db->commit();
    }

    private function runConcurrently(\Closure $left, \Closure $right): void
    {
        $directory = sys_get_temp_dir().'/nntmux-part-probe-'.bin2hex(random_bytes(6));
        mkdir($directory, 0700, true);
        $pids = [];

        foreach (['left' => $left, 'right' => $right] as $name => $operation) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                self::fail('Unable to fork MariaDB concurrency worker.');
            }
            if ($pid === 0) {
                try {
                    touch("{$directory}/{$name}.ready");
                    $deadline = microtime(true) + 10;
                    while (! is_file("{$directory}/go")) {
                        if (microtime(true) >= $deadline) {
                            throw new \RuntimeException('Concurrency barrier timed out.');
                        }
                        usleep(1_000);
                    }
                    $operation();
                    exit(0);
                } catch (\Throwable $e) {
                    file_put_contents("{$directory}/{$name}.error", $e::class.': '.$e->getMessage());
                    exit(1);
                }
            }
            $pids[$name] = $pid;
        }

        $deadline = microtime(true) + 10;
        while ((! is_file("{$directory}/left.ready") || ! is_file("{$directory}/right.ready")) && microtime(true) < $deadline) {
            usleep(1_000);
        }
        touch("{$directory}/go");

        $errors = [];
        foreach ($pids as $name => $pid) {
            pcntl_waitpid($pid, $status);
            if (! pcntl_wifexited($status) || pcntl_wexitstatus($status) !== 0) {
                $errors[] = $name.': '.(is_file("{$directory}/{$name}.error") ? file_get_contents("{$directory}/{$name}.error") : 'child failed');
            }
        }

        foreach (glob("{$directory}/*") ?: [] as $path) {
            unlink($path);
        }
        rmdir($directory);
        self::assertSame([], $errors);
    }

    private function deadlockCount(): int
    {
        $row = $this->connect()->query("SHOW GLOBAL STATUS LIKE 'Innodb_deadlocks'")->fetch(PDO::FETCH_NUM);

        return (int) ($row[1] ?? 0);
    }

    private function connect(): PDO
    {
        return new PDO($this->dsn, $this->user, $this->password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
}
