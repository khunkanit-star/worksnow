<?php
declare(strict_types=1);

/** Shared sessions survive container replacement and requests on other instances. */
final class DatabaseSessionHandler implements SessionHandlerInterface, SessionUpdateTimestampHandlerInterface
{
    private ?string $lock = null;

    public function __construct(private PDO $connection, private bool $mysql) {}

    public function open(string $path, string $name): bool { return true; }

    public function close(): bool
    {
        if ($this->lock !== null) {
            $statement = $this->connection->prepare('SELECT RELEASE_LOCK(?)');
            $statement->execute([$this->lock]);
            $this->lock = null;
        }
        return true;
    }

    public function read(string $id): string|false
    {
        if ($this->mysql) {
            $this->close();
            $key = hash('sha256', 'worksnow-session:' . $id);
            $statement = $this->connection->prepare('SELECT GET_LOCK(?, 10)');
            $statement->execute([$key]);
            if ((int)$statement->fetchColumn() !== 1) throw new RuntimeException('Could not lock the session.');
            $this->lock = $key;
        }
        $statement = $this->connection->prepare('SELECT data FROM sessions WHERE id=? AND expires_at>?');
        $statement->execute([$id, time()]);
        return $statement->fetchColumn() ?: '';
    }

    public function write(string $id, string $data): bool
    {
        $sql = $this->mysql
            ? 'INSERT INTO sessions (id,data,expires_at) VALUES (?,?,?) ON DUPLICATE KEY UPDATE data=VALUES(data),expires_at=VALUES(expires_at)'
            : 'INSERT INTO sessions (id,data,expires_at) VALUES (?,?,?) ON CONFLICT(id) DO UPDATE SET data=excluded.data,expires_at=excluded.expires_at';
        return $this->connection->prepare($sql)->execute([$id, $data, time() + (int)ini_get('session.gc_maxlifetime')]);
    }

    public function destroy(string $id): bool
    {
        return $this->connection->prepare('DELETE FROM sessions WHERE id=?')->execute([$id]);
    }

    public function gc(int $max_lifetime): int|false
    {
        $statement = $this->connection->prepare('DELETE FROM sessions WHERE expires_at<?');
        $statement->execute([time()]);
        return $statement->rowCount();
    }

    public function validateId(string $id): bool
    {
        $statement = $this->connection->prepare('SELECT id FROM sessions WHERE id=? AND expires_at>?');
        $statement->execute([$id, time()]);
        return $statement->fetchColumn() !== false;
    }

    public function updateTimestamp(string $id, string $data): bool
    {
        return $this->connection->prepare('UPDATE sessions SET expires_at=? WHERE id=?')->execute([time() + (int)ini_get('session.gc_maxlifetime'), $id]);
    }
}
