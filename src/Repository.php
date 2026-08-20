<?php

declare(strict_types=1);

final class Repository
{
    public function __construct(private readonly \PDO $pdo)
    {
    }

    public function listVisits(string $sort): array
    {
        $orderBy = match ($sort) {
            'name' => 'display_name ASC, visits.created_at DESC',
            'establishment' => 'establishment_name IS NULL, establishment_name ASC, display_name ASC',
            default => 'visited_on IS NULL, visits.visited_on DESC, display_name ASC',
        };

        $statement = $this->pdo->query(
            "SELECT
                visits.id,
                visits.visitor_name,
                visits.visited_on,
                visits.created_at,
                visits.person_id,
                visits.establishment_id,
                COALESCE(people.name, visits.visitor_name) AS display_name,
                people.profession,
                people.photo_url,
                people.birth_date,
                establishments.name AS establishment_name
            FROM visits
            LEFT JOIN people ON people.id = visits.person_id
            LEFT JOIN establishments ON establishments.id = visits.establishment_id
            ORDER BY {$orderBy}"
        );

        return $statement->fetchAll();
    }

    public function listVisitsNeedingEnrichment(): array
    {
        $statement = $this->pdo->query(
            'SELECT
                visits.id,
                visits.visitor_name,
                visits.visited_on,
                visits.created_at,
                visits.person_id,
                visits.establishment_id,
                COALESCE(people.name, visits.visitor_name) AS display_name,
                people.profession,
                people.photo_url,
                people.birth_date,
                establishments.name AS establishment_name
            FROM visits
            LEFT JOIN people ON people.id = visits.person_id
            LEFT JOIN establishments ON establishments.id = visits.establishment_id
            WHERE visits.person_id IS NULL
                OR visits.visited_on IS NULL
                OR visits.establishment_id IS NULL
            ORDER BY visits.created_at DESC'
        );

        return $statement->fetchAll();
    }

    public function getVisit(int $id): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT
                visits.id,
                visits.visitor_name,
                visits.visited_on,
                visits.created_at,
                visits.person_id,
                visits.establishment_id,
                COALESCE(people.name, visits.visitor_name) AS display_name,
                people.profession,
                people.photo_url,
                people.birth_date,
                establishments.name AS establishment_name
            FROM visits
            LEFT JOIN people ON people.id = visits.person_id
            LEFT JOIN establishments ON establishments.id = visits.establishment_id
            WHERE visits.id = :id'
        );
        $statement->execute(['id' => $id]);
        $visit = $statement->fetch();

        return $visit === false ? null : $visit;
    }

    public function listEstablishments(): array
    {
        $statement = $this->pdo->query('SELECT id, name FROM establishments ORDER BY name ASC');

        return $statement->fetchAll();
    }

    public function createEstablishment(string $name): void
    {
        $statement = $this->pdo->prepare('INSERT INTO establishments (name) VALUES (:name)');
        $statement->execute(['name' => $name]);
    }

    public function updateEstablishment(int $id, string $name): void
    {
        $statement = $this->pdo->prepare('UPDATE establishments SET name = :name WHERE id = :id');
        $statement->execute(['id' => $id, 'name' => $name]);
    }

    public function deleteEstablishment(int $id): void
    {
        $statement = $this->pdo->prepare('DELETE FROM establishments WHERE id = :id');
        $statement->execute(['id' => $id]);
    }

    public function upsertPerson(array $person): int
    {
        $existing = $this->findPersonByWikidataId($person['wikidata_id']);

        if ($existing !== null) {
            $statement = $this->pdo->prepare(
                'UPDATE people
                SET name = :name,
                    profession = :profession,
                    birth_date = :birth_date,
                    photo_url = :photo_url
                WHERE id = :id'
            );
            $statement->execute([
                'id' => $existing['id'],
                'name' => $person['name'],
                'profession' => $person['profession'],
                'birth_date' => $person['birth_date'],
                'photo_url' => $person['photo_url'],
            ]);

            return (int) $existing['id'];
        }

        if ($this->driver() === 'pgsql') {
            $statement = $this->pdo->prepare(
                'INSERT INTO people (wikidata_id, name, profession, birth_date, photo_url)
                VALUES (:wikidata_id, :name, :profession, :birth_date, :photo_url)
                RETURNING id'
            );
            $statement->execute([
                'wikidata_id' => $person['wikidata_id'],
                'name' => $person['name'],
                'profession' => $person['profession'],
                'birth_date' => $person['birth_date'],
                'photo_url' => $person['photo_url'],
            ]);

            return (int) $statement->fetchColumn();
        }

        $statement = $this->pdo->prepare(
            'INSERT INTO people (wikidata_id, name, profession, birth_date, photo_url)
            VALUES (:wikidata_id, :name, :profession, :birth_date, :photo_url)'
        );
        $statement->execute([
            'wikidata_id' => $person['wikidata_id'],
            'name' => $person['name'],
            'profession' => $person['profession'],
            'birth_date' => $person['birth_date'],
            'photo_url' => $person['photo_url'],
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function createVisit(string $visitorName, ?int $personId, ?string $visitedOn, ?int $establishmentId): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO visits (visitor_name, person_id, visited_on, establishment_id)
            VALUES (:visitor_name, :person_id, :visited_on, :establishment_id)'
        );
        $statement->execute([
            'visitor_name' => $visitorName,
            'person_id' => $personId,
            'visited_on' => $visitedOn,
            'establishment_id' => $establishmentId,
        ]);
    }

    public function updateVisitMetadata(int $id, ?string $visitedOn, ?int $establishmentId): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE visits
            SET visited_on = :visited_on,
                establishment_id = :establishment_id
            WHERE id = :id'
        );
        $statement->execute([
            'id' => $id,
            'visited_on' => $visitedOn,
            'establishment_id' => $establishmentId,
        ]);
    }

    public function resolveVisit(int $visitId, int $personId): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE visits SET person_id = :person_id WHERE id = :id'
        );
        $statement->execute([
            'id' => $visitId,
            'person_id' => $personId,
        ]);
    }

    private function findPersonByWikidataId(string $wikidataId): ?array
    {
        $statement = $this->pdo->prepare('SELECT id FROM people WHERE wikidata_id = :wikidata_id');
        $statement->execute(['wikidata_id' => $wikidataId]);
        $person = $statement->fetch();

        return $person === false ? null : $person;
    }

    private function driver(): string
    {
        return (string) $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
    }
}
