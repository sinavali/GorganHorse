<?php
declare(strict_types=1);

/**
 * File: app/Bootstrap/Database.php
 *
 * Purpose:
 *   PDO/SQLite connection management. Opens the main (`app.sqlite`) and logs
 *   (`logs.sqlite`) databases, applies the required pragmas (WAL, foreign keys,
 *   busy timeout, synchronous NORMAL), and exposes transaction helpers, a
 *   query logger for slow queries, and a small query helper set used by models
 *   and services (Technical §7).
 *
 * @package App\Bootstrap
 */

namespace App\Bootstrap;

use PDO;
use PDOException;
use PDOStatement;
use Throwable;

/**
 * Class: Database
 *
 * Purpose: Wrap a single SQLite database file with a configured PDO handle.
 */
final class Database
{
    private ?PDO $pdo = null;
    private string $file;
    /** @var callable|null function(string $message, array $context, int $ms): void */
    private $slowLogger;
    private int $slowThresholdMs;
    /** @var array<string,PDOStatement> Prepared-statement cache (bounded). */
    private array $statements = [];

    public function __construct(string $file, ?callable $slowLogger = null, int $slowThresholdMs = 200)
    {
        $this->file = $file;
        $this->slowLogger = $slowLogger;
        $this->slowThresholdMs = $slowThresholdMs;
    }

    /** @return string Absolute database file path. */
    public function file(): string { return $this->file; }

    /**
     * Drop the PDO handle so the database file can be replaced safely.
     *
     * Used by the backup restore flow before copying a new app.sqlite over the
     * live file; the handle reopens lazily on the next query.
     *
     * @return void
     */
    public function close(): void
    {
        $this->statements = [];
        $this->pdo = null;
    }

    /** @return PDO */
    public function pdo(): PDO
    {
        if ($this->pdo instanceof PDO) {
            return $this->pdo;
        }
        $dir = dirname($this->file);
        if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
        $pdo = new PDO('sqlite:' . $this->file, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $pdo->exec('PRAGMA journal_mode=WAL');
        $pdo->exec('PRAGMA foreign_keys=ON');
        $pdo->exec('PRAGMA busy_timeout=5000');
        $pdo->exec('PRAGMA synchronous=NORMAL');
        return $this->pdo = $pdo;
    }

    public function execute(string $sql, array $params = []): int
    {
        return $this->timed($sql, $params)->rowCount();
    }

    public function selectOne(string $sql, array $params = []): ?array
    {
        $row = $this->timed($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    public function select(string $sql, array $params = []): array
    {
        return $this->timed($sql, $params)->fetchAll();
    }

    public function scalar(string $sql, array $params = [], mixed $default = null): mixed
    {
        $value = $this->timed($sql, $params)->fetchColumn();
        return $value === false ? $default : $value;
    }

    public function insert(string $table, array $data): int
    {
        $columns = array_keys($data);
        $placeholders = array_map(static fn (string $c): string => ':' . $c, $columns);
        $sql = sprintf('INSERT INTO %s (%s) VALUES (%s)', $table, implode(', ', $columns), implode(', ', $placeholders));
        $this->timed($sql, $data);
        return (int) $this->pdo()->lastInsertId();
    }

    /**
     * Update rows matching a WHERE clause; returns affected count.
     *
     * Fix: the WHERE clause is rewritten to prefix every bare `:param` with
     * `:w_`. Parameters that are already prefixed with `w_` (or supplied as
     * `:w_param`) are preserved as-is, avoiding the historical `:w_w_param`
     * double-prefix bug.
     */
    public function update(string $table, array $data, string $where, array $whereParams = []): int
    {
        $sets = array_map(static fn (string $c): string => $c . ' = :set_' . $c, array_keys($data));
        $bound = [];
        foreach ($data as $k => $v) {
            $bound['set_' . $k] = $v;
        }
        foreach ($whereParams as $k => $v) {
            $key = ltrim((string) $k, ':');
            if (str_starts_with($key, 'w_')) {
                $bound[$key] = $v;
            } else {
                $bound['w_' . $key] = $v;
            }
        }
        $where = preg_replace_callback(
            '/:([a-zA-Z_][a-zA-Z0-9_]*)/',
            static function (array $m): string {
                return str_starts_with($m[1], 'w_') ? ':' . $m[1] : ':w_' . $m[1];
            },
            $where
        ) ?? $where;
        $sql = sprintf('UPDATE %s SET %s WHERE %s', $table, implode(', ', $sets), $where);
        return $this->timed($sql, $bound)->rowCount();
    }

    public function delete(string $table, string $where, array $whereParams = []): int
    {
        $sql = sprintf('DELETE FROM %s WHERE %s', $table, $where);
        return $this->timed($sql, $whereParams)->rowCount();
    }

    public function transaction(callable $fn, bool $immediate = true): mixed
    {
        $pdo = $this->pdo();
        if ($pdo->inTransaction()) {
            return $fn($this);
        }
        $pdo->exec($immediate ? 'BEGIN IMMEDIATE' : 'BEGIN');
        try {
            $result = $fn($this);
            $pdo->exec('COMMIT');
            return $result;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->exec('ROLLBACK');
            }
            throw $e;
        }
    }

    private function timed(string $sql, array $params): PDOStatement
    {
        // Reuse prepared statements: the panel issues a stable, small set of
        // SQL strings, so preparing each one on every call is wasted work.
        $stmt = $this->statements[$sql] ?? null;
        if (!$stmt instanceof PDOStatement) {
            if (count($this->statements) >= 256) {
                array_shift($this->statements);
            }
            $stmt = $this->statements[$sql] = $this->pdo()->prepare($sql);
        }
        $start = hrtime(true);
        $stmt->execute($params);
        $ms = (int) ((hrtime(true) - $start) / 1_000_000);
        if ($ms >= $this->slowThresholdMs && $this->slowLogger !== null) {
            ($this->slowLogger)('Slow query detected', ['sql' => $sql, 'ms' => $ms, 'params' => $params], $ms);
        }
        return $stmt;
    }

    /**
     * Execute a raw statement (no parameters).
     *
     * Used for maintenance statements such as VACUUM / ANALYZE / PRAGMA that
     * SQLite refuses to run while prepared statements are still alive, so the
     * statement cache is discarded first.
     *
     * @param string $sql Raw SQL.
     * @return void
     */
    public function exec(string $sql): void
    {
        $this->statements = [];
        $this->pdo()->exec($sql);
    }

    public function applyScript(string $sql): void
    {
        $this->statements = [];
        $this->pdo()->exec($sql);
    }
}