<?php

class Node
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function all(): array
    {
        $stmt = $this->pdo->query("SELECT * FROM nodes ORDER BY name ASC");
        return $stmt->fetchAll();
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM nodes WHERE id = ?");
        $stmt->execute([$id]);

        $node = $stmt->fetch();

        return $node ?: null;
    }

    public function create(string $name, string $site, string $provider, string $ip): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO nodes (name, site, provider, mgmt_ip)
             VALUES (?, ?, ?, ?)"
        );

        $stmt->execute([$name, $site, $provider, $ip]);

        return (int)$this->pdo->lastInsertId();
    }
}