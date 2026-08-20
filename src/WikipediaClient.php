<?php

declare(strict_types=1);

final class WikipediaClient
{
    public function searchPeople(string $name): array
    {
        $response = $this->fetchJson(
            'https://www.wikidata.org/w/api.php?action=wbsearchentities&format=json&language=fr&type=item&limit=8&search=' . rawurlencode($name)
        );

        if ($response === null || !isset($response['search']) || !is_array($response['search'])) {
            return [];
        }

        $matches = array_values(array_filter(
            $response['search'],
            static fn (array $candidate): bool => isset($candidate['id'], $candidate['label'])
        ));

        $exactMatches = array_values(array_filter($matches, static function (array $candidate) use ($name): bool {
            return mb_strtolower((string) $candidate['label']) === mb_strtolower($name);
        }));

        $selectedMatches = $exactMatches !== [] ? $exactMatches : array_slice($matches, 0, 5);
        $candidates = [];

        foreach ($selectedMatches as $match) {
            $person = $this->fetchPersonById((string) $match['id']);

            if ($person === null) {
                continue;
            }

            $person['profession'] = $person['profession'] ?: ($match['description'] ?? null);
            $candidates[$person['wikidata_id']] = $person;
        }

        return array_values($candidates);
    }

    public function fetchPersonById(string $wikidataId): ?array
    {
        $response = $this->fetchJson('https://www.wikidata.org/wiki/Special:EntityData/' . rawurlencode($wikidataId) . '.json');
        $entity = $response['entities'][$wikidataId] ?? null;

        if (!is_array($entity)) {
            return null;
        }

        $name = $entity['labels']['fr']['value']
            ?? $entity['labels']['en']['value']
            ?? $wikidataId;

        $profession = $entity['descriptions']['fr']['value']
            ?? $entity['descriptions']['en']['value']
            ?? null;

        return [
            'wikidata_id' => $wikidataId,
            'name' => $name,
            'profession' => $profession,
            'birth_date' => $this->parseWikidataDate($this->extractClaimValue($entity, 'P569', 'time')),
            'photo_url' => $this->buildPhotoUrl($this->extractClaimValue($entity, 'P18')),
        ];
    }

    private function buildPhotoUrl(?string $filename): ?string
    {
        if ($filename === null || $filename === '') {
            return null;
        }

        return 'https://commons.wikimedia.org/wiki/Special:FilePath/' . rawurlencode($filename);
    }

    private function parseWikidataDate(?string $value): ?string
    {
        if ($value === null || !preg_match('/^[+-](\d{4})-(\d{2})-(\d{2})T/', $value, $matches)) {
            return null;
        }

        return sprintf('%s-%s-%s', $matches[1], $matches[2], $matches[3]);
    }

    private function extractClaimValue(array $entity, string $property, string $key = 'value'): ?string
    {
        $claim = $entity['claims'][$property][0]['mainsnak']['datavalue']['value'] ?? null;

        if (is_array($claim)) {
            $claim = $claim[$key] ?? null;
        }

        return is_string($claim) ? $claim : null;
    }

    private function fetchJson(string $url): ?array
    {
        if (function_exists('curl_init')) {
            $curl = curl_init($url);
            curl_setopt_array($curl, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTPHEADER => ['Accept: application/json'],
                CURLOPT_TIMEOUT => 10,
                CURLOPT_USERAGENT => 'hall-of-fame/1.0',
            ]);

            $response = curl_exec($curl);
            $statusCode = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            curl_close($curl);

            if (is_string($response) && $statusCode >= 200 && $statusCode < 400) {
                $decoded = json_decode($response, true);

                return is_array($decoded) ? $decoded : null;
            }
        }

        $context = stream_context_create([
            'http' => [
                'header' => "User-Agent: hall-of-fame/1.0\r\nAccept: application/json\r\n",
                'timeout' => 10,
            ],
        ]);

        $response = @file_get_contents($url, false, $context);

        if ($response === false) {
            return null;
        }

        $decoded = json_decode($response, true);

        return is_array($decoded) ? $decoded : null;
    }
}
