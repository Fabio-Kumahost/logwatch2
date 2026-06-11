<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

/** Base: shared PDO helpers. Subclasses use prepared statements exclusively. */
abstract class Repository
{
    public function __construct(protected readonly PDO $pdo)
    {
    }

    /** @param list<mixed> $params */
    protected function row(string $sql, array $params = []): ?object
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_OBJ);
        return $row === false ? null : $row;
    }

    /** @param list<mixed> $params @return list<object> */
    protected function rows(string $sql, array $params = []): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_OBJ);
    }

    /** @param list<mixed> $params */
    protected function scalar(string $sql, array $params = []): mixed
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    }

    /** @param list<mixed> $params @return int affected rows */
    protected function exec(string $sql, array $params = []): int
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }
}
