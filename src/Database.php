<?php

declare(strict_types=1);

final class Database
{
    private \PDO $pdo;

    public function __construct(string $dsn, string $user = '', string $password = '')
    {
        $this->pdo = new \PDO($dsn, $user, $password, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);

        if ($this->driver() === 'sqlite') {
            $this->pdo->exec('PRAGMA foreign_keys = ON');
        }
    }

    public function pdo(): \PDO
    {
        return $this->pdo;
    }

    public function driver(): string
    {
        return (string) $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
    }

    public function ensureSchema(): void
    {
        if ($this->driver() === 'pgsql') {
            $this->createPostgreSqlSchema();

            return;
        }

        $this->createSqliteSchema();
    }

    private function createSqliteSchema(): void
    {
        $statements = [
            'CREATE TABLE IF NOT EXISTS people (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                wikidata_id TEXT NOT NULL UNIQUE,
                name TEXT NOT NULL,
                profession TEXT NULL,
                birth_date TEXT NULL,
                photo_url TEXT NULL
            )',
            'CREATE TABLE IF NOT EXISTS establishments (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL UNIQUE
            )',
            'CREATE TABLE IF NOT EXISTS visits (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                visitor_name TEXT NOT NULL,
                person_id INTEGER NULL,
                visited_on TEXT NULL,
                establishment_id INTEGER NULL,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (person_id) REFERENCES people (id) ON DELETE SET NULL,
                FOREIGN KEY (establishment_id) REFERENCES establishments (id) ON DELETE SET NULL
            )',
            'CREATE INDEX IF NOT EXISTS visits_person_idx ON visits (person_id)',
            'CREATE INDEX IF NOT EXISTS visits_establishment_idx ON visits (establishment_id)',
            'CREATE INDEX IF NOT EXISTS visits_date_idx ON visits (visited_on)',
        ];

        foreach ($statements as $statement) {
            $this->pdo->exec($statement);
        }
    }

    private function createPostgreSqlSchema(): void
    {
        $statements = [
            'CREATE TABLE IF NOT EXISTS people (
                id INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                wikidata_id TEXT NOT NULL UNIQUE,
                name TEXT NOT NULL,
                profession TEXT NULL,
                birth_date DATE NULL,
                photo_url TEXT NULL
            )',
            'CREATE TABLE IF NOT EXISTS establishments (
                id INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                name TEXT NOT NULL UNIQUE
            )',
            'CREATE TABLE IF NOT EXISTS visits (
                id INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                visitor_name TEXT NOT NULL,
                person_id INTEGER NULL REFERENCES people (id) ON DELETE SET NULL,
                visited_on DATE NULL,
                establishment_id INTEGER NULL REFERENCES establishments (id) ON DELETE SET NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            )',
            'CREATE INDEX IF NOT EXISTS visits_person_idx ON visits (person_id)',
            'CREATE INDEX IF NOT EXISTS visits_establishment_idx ON visits (establishment_id)',
            'CREATE INDEX IF NOT EXISTS visits_date_idx ON visits (visited_on)',
        ];

        foreach ($statements as $statement) {
            $this->pdo->exec($statement);
        }
    }
}
