<?php

declare(strict_types=1);

session_start();

require __DIR__ . '/src/Database.php';
require __DIR__ . '/src/Repository.php';
require __DIR__ . '/src/WikipediaClient.php';

$storageDirectory = __DIR__ . '/var';

if (!is_dir($storageDirectory)) {
    mkdir($storageDirectory, 0777, true);
}

$database = new Database(
    getenv('DB_DSN') ?: 'sqlite:' . $storageDirectory . '/hall-of-fame.sqlite',
    getenv('DB_USER') ?: '',
    getenv('DB_PASSWORD') ?: ''
);
$database->ensureSchema();

$repository = new Repository($database->pdo());
$wikipedia = new WikipediaClient();

$page = $_GET['page'] ?? 'visits';
$sort = in_array($_GET['sort'] ?? 'date', ['date', 'name', 'establishment'], true) ? (string) ($_GET['sort'] ?? 'date') : 'date';
$candidateSelection = null;
$candidateVisit = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = $_POST['action'] ?? '';

        switch ($action) {
            case 'add_establishment':
                $name = trim((string) ($_POST['name'] ?? ''));
                if ($name === '') {
                    throw new RuntimeException('Le nom de l’établissement est obligatoire.');
                }
                $repository->createEstablishment($name);
                flash('success', 'Établissement ajouté.');
                redirect('?page=establishments');
                break;

            case 'update_establishment':
                $name = trim((string) ($_POST['name'] ?? ''));
                $id = (int) ($_POST['id'] ?? 0);
                if ($id < 1 || $name === '') {
                    throw new RuntimeException('Impossible de mettre à jour cet établissement.');
                }
                $repository->updateEstablishment($id, $name);
                flash('success', 'Établissement mis à jour.');
                redirect('?page=establishments');
                break;

            case 'delete_establishment':
                $id = (int) ($_POST['id'] ?? 0);
                if ($id < 1) {
                    throw new RuntimeException('Établissement introuvable.');
                }
                $repository->deleteEstablishment($id);
                flash('success', 'Établissement supprimé.');
                redirect('?page=establishments');
                break;

            case 'search_visit_person':
                $name = trim((string) ($_POST['visitor_name'] ?? ''));
                $visitedOn = normalizeDate($_POST['visited_on'] ?? null);
                $establishmentId = normalizeInteger($_POST['establishment_id'] ?? null);

                if ($name === '') {
                    throw new RuntimeException('Le nom de la personnalité est obligatoire.');
                }

                $candidates = $wikipedia->searchPeople($name);

                if (count($candidates) === 0) {
                    $repository->createVisit($name, null, $visitedOn, $establishmentId);
                    flash('success', 'Visite enregistrée sans correspondance Wikipédia. Vous pourrez la compléter depuis le préchargement.');
                    redirect('?page=preload');
                }

                if (count($candidates) === 1) {
                    $personId = $repository->upsertPerson($candidates[0]);
                    $repository->createVisit($name, $personId, $visitedOn, $establishmentId);
                    flash('success', 'Visite enregistrée.');
                    redirect('?page=visits');
                }

                $page = 'new-visit';
                $candidateSelection = [
                    'visitor_name' => $name,
                    'visited_on' => $visitedOn ?? date('Y-m-d'),
                    'establishment_id' => $establishmentId,
                    'candidates' => $candidates,
                ];
                break;

            case 'save_visit_person':
                $name = trim((string) ($_POST['visitor_name'] ?? ''));
                $visitedOn = normalizeDate($_POST['visited_on'] ?? null);
                $establishmentId = normalizeInteger($_POST['establishment_id'] ?? null);
                $wikidataId = trim((string) ($_POST['wikidata_id'] ?? ''));

                if ($name === '' || $wikidataId === '') {
                    throw new RuntimeException('Sélection incomplète.');
                }

                $person = $wikipedia->fetchPersonById($wikidataId);

                if ($person === null) {
                    throw new RuntimeException('Impossible de récupérer cette personnalité depuis Wikipédia.');
                }

                $personId = $repository->upsertPerson($person);
                $repository->createVisit($name, $personId, $visitedOn, $establishmentId);
                flash('success', 'Visite enregistrée.');
                redirect('?page=visits');
                break;

            case 'update_visit':
                $visitId = (int) ($_POST['visit_id'] ?? 0);
                if ($visitId < 1) {
                    throw new RuntimeException('Visite introuvable.');
                }
                $repository->updateVisitMetadata(
                    $visitId,
                    normalizeDate($_POST['visited_on'] ?? null),
                    normalizeInteger($_POST['establishment_id'] ?? null)
                );
                flash('success', 'Visite mise à jour.');
                redirect('?page=preload');
                break;

            case 'resolve_visit_person':
                $visitId = (int) ($_POST['visit_id'] ?? 0);
                $wikidataId = trim((string) ($_POST['wikidata_id'] ?? ''));

                if ($visitId < 1 || $wikidataId === '') {
                    throw new RuntimeException('Sélection incomplète.');
                }

                $person = $wikipedia->fetchPersonById($wikidataId);

                if ($person === null) {
                    throw new RuntimeException('Impossible de récupérer cette personnalité depuis Wikipédia.');
                }

                $personId = $repository->upsertPerson($person);
                $repository->resolveVisit($visitId, $personId);
                flash('success', 'Personnalité associée à la visite.');
                redirect('?page=preload');
                break;

            case 'import_visitors':
                if (!isset($_FILES['visitors_file']) || !is_uploaded_file($_FILES['visitors_file']['tmp_name'])) {
                    throw new RuntimeException('Choisissez un fichier à importer.');
                }

                $result = importVisitors($_FILES['visitors_file']['tmp_name'], $repository, $wikipedia);
                flash(
                    'success',
                    sprintf(
                        'Import terminé : %d ligne(s), %d visite(s) résolue(s), %d à compléter.',
                        $result['total'],
                        $result['resolved'],
                        $result['pending']
                    )
                );
                redirect('?page=preload');
                break;
        }
    } catch (Throwable $exception) {
        flash('error', $exception->getMessage());
        redirect('?page=' . urlencode($page));
    }
}

$establishments = $repository->listEstablishments();
$flash = consumeFlash();

if ($page === 'preload' && isset($_GET['resolve'])) {
    $candidateVisit = $repository->getVisit((int) $_GET['resolve']);

    if ($candidateVisit !== null) {
        $candidateSelection = [
            'visit_id' => (int) $candidateVisit['id'],
            'visitor_name' => (string) $candidateVisit['visitor_name'],
            'candidates' => $wikipedia->searchPeople((string) $candidateVisit['visitor_name']),
        ];
    }
}

$title = 'Hall of Fame';
$content = '';

ob_start();
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title><?= h($title) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        :root { color-scheme: light; }
        body { margin: 0; font-family: Arial, sans-serif; background: #f4f5f7; color: #1f2933; }
        header { background: #172b4d; color: #fff; padding: 1rem 1.5rem; }
        nav { display: flex; gap: 1rem; flex-wrap: wrap; margin-top: .75rem; }
        nav a { color: #fff; text-decoration: none; font-weight: 700; }
        main { max-width: 1100px; margin: 0 auto; padding: 1.5rem; }
        .flash { padding: 1rem; border-radius: .5rem; margin-bottom: 1rem; }
        .flash.success { background: #d9f2e3; color: #125d36; }
        .flash.error { background: #f9d8d6; color: #7d1d18; }
        .panel { background: #fff; border-radius: .75rem; box-shadow: 0 3px 8px rgba(15, 23, 42, .08); padding: 1.25rem; margin-bottom: 1rem; }
        .visits { display: grid; gap: 1rem; }
        .visit-card { display: grid; grid-template-columns: 84px 1fr; gap: 1rem; align-items: start; }
        .thumbnail, .thumbnail img { width: 84px; height: 84px; border-radius: .75rem; object-fit: cover; background: #dfe1e6; }
        .thumbnail-fallback { display: flex; align-items: center; justify-content: center; font-size: 1.5rem; font-weight: 700; color: #42526e; }
        .muted { color: #6b778c; }
        .meta { display: flex; gap: .75rem; flex-wrap: wrap; margin-top: .5rem; }
        .badge { background: #eef2f7; border-radius: 999px; padding: .3rem .7rem; }
        table { width: 100%; border-collapse: collapse; }
        th, td { text-align: left; padding: .75rem; border-bottom: 1px solid #e5e7eb; vertical-align: top; }
        form.inline { display: inline; }
        input[type="text"], input[type="date"], select, input[type="file"] { width: 100%; box-sizing: border-box; padding: .65rem .75rem; border-radius: .5rem; border: 1px solid #cbd2d9; }
        button { border: 0; border-radius: .5rem; padding: .7rem 1rem; background: #0052cc; color: #fff; font-weight: 700; cursor: pointer; }
        button.secondary, a.secondary { background: #ebecf0; color: #172b4d; text-decoration: none; display: inline-block; }
        .stack { display: grid; gap: 1rem; }
        .row { display: grid; gap: 1rem; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); }
        .choice { border: 1px solid #dfe1e6; border-radius: .75rem; padding: 1rem; margin-bottom: .75rem; }
    </style>
</head>
<body>
<header>
    <h1>Hall of Fame</h1>
    <p>Personnalités croisées dans les établissements où vous avez travaillé.</p>
    <nav>
        <a href="?page=visits">Visites</a>
        <a href="?page=new-visit">Nouvelle visite</a>
        <a href="?page=establishments">Établissements</a>
        <a href="?page=import">Import</a>
        <a href="?page=preload">Préchargement</a>
    </nav>
</header>
<main>
    <?php if ($flash !== null): ?>
        <div class="flash <?= h($flash['type']) ?>"><?= h($flash['message']) ?></div>
    <?php endif; ?>

    <?php if ($page === 'visits'): ?>
        <?php $visits = $repository->listVisits($sort); ?>
        <section class="panel">
            <h2>Liste des visites</h2>
            <p>Tri :
                <a href="?page=visits&sort=date">date</a> ·
                <a href="?page=visits&sort=establishment">établissement</a> ·
                <a href="?page=visits&sort=name">nom</a>
            </p>
        </section>
        <section class="visits">
            <?php foreach ($visits as $visit): ?>
                <article class="panel visit-card">
                    <?php if (!empty($visit['photo_url'])): ?>
                        <div class="thumbnail"><img src="<?= h((string) $visit['photo_url']) ?>" alt=""></div>
                    <?php else: ?>
                        <div class="thumbnail thumbnail-fallback"><?= h(mb_substr((string) $visit['display_name'], 0, 1)) ?></div>
                    <?php endif; ?>
                    <div>
                        <h3><?= h((string) $visit['display_name']) ?></h3>
                        <p class="muted"><?= h((string) ($visit['profession'] ?? 'Profession à compléter')) ?></p>
                        <div class="meta">
                            <span class="badge">Visite : <?= h(formatDate($visit['visited_on'] ?? null)) ?></span>
                            <span class="badge">Établissement : <?= h((string) ($visit['establishment_name'] ?? 'À renseigner')) ?></span>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>
            <?php if ($visits === []): ?>
                <section class="panel">
                    <p>Aucune visite enregistrée pour le moment.</p>
                </section>
            <?php endif; ?>
        </section>
    <?php elseif ($page === 'new-visit'): ?>
        <section class="panel">
            <h2>Nouvelle visite</h2>
            <form method="post" class="stack">
                <input type="hidden" name="action" value="search_visit_person">
                <div class="row">
                    <label>Nom de la personnalité
                        <input type="text" name="visitor_name" required value="<?= h((string) ($candidateSelection['visitor_name'] ?? '')) ?>">
                    </label>
                    <label>Date de visite
                        <input type="date" name="visited_on" value="<?= h((string) ($candidateSelection['visited_on'] ?? date('Y-m-d'))) ?>">
                    </label>
                    <label>Établissement
                        <select name="establishment_id">
                            <option value="">Non renseigné</option>
                            <?php foreach ($establishments as $establishment): ?>
                                <option value="<?= h((string) $establishment['id']) ?>" <?= selected((int) $establishment['id'], $candidateSelection['establishment_id'] ?? null) ?>>
                                    <?= h((string) $establishment['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </div>
                <button type="submit">Chercher sur Wikipédia et enregistrer</button>
            </form>
        </section>

        <?php if ($candidateSelection !== null && isset($candidateSelection['candidates']) && count($candidateSelection['candidates']) > 1): ?>
            <section class="panel">
                <h2>Choisir la bonne personnalité</h2>
                <form method="post" class="stack">
                    <input type="hidden" name="action" value="save_visit_person">
                    <input type="hidden" name="visitor_name" value="<?= h((string) $candidateSelection['visitor_name']) ?>">
                    <input type="hidden" name="visited_on" value="<?= h((string) $candidateSelection['visited_on']) ?>">
                    <input type="hidden" name="establishment_id" value="<?= h((string) ($candidateSelection['establishment_id'] ?? '')) ?>">
                    <?php foreach ($candidateSelection['candidates'] as $index => $candidate): ?>
                        <label class="choice">
                            <input type="radio" name="wikidata_id" value="<?= h((string) $candidate['wikidata_id']) ?>" <?= $index === 0 ? 'checked' : '' ?>>
                            <strong><?= h((string) $candidate['name']) ?></strong><br>
                            <span class="muted"><?= h((string) ($candidate['profession'] ?? 'Profession inconnue')) ?></span><br>
                            <span class="muted">Né(e) le : <?= h(formatDate($candidate['birth_date'] ?? null)) ?></span>
                        </label>
                    <?php endforeach; ?>
                    <button type="submit">Enregistrer la visite</button>
                </form>
            </section>
        <?php endif; ?>
    <?php elseif ($page === 'establishments'): ?>
        <section class="panel">
            <h2>Établissements</h2>
            <form method="post" class="row">
                <input type="hidden" name="action" value="add_establishment">
                <label>Nom
                    <input type="text" name="name" required>
                </label>
                <div style="align-self:end">
                    <button type="submit">Ajouter</button>
                </div>
            </form>
        </section>
        <section class="panel">
            <table>
                <thead>
                    <tr>
                        <th>Nom</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($establishments as $establishment): ?>
                    <tr>
                        <td>
                            <form method="post" class="row">
                                <input type="hidden" name="action" value="update_establishment">
                                <input type="hidden" name="id" value="<?= h((string) $establishment['id']) ?>">
                                <input type="text" name="name" value="<?= h((string) $establishment['name']) ?>" required>
                                <button type="submit">Renommer</button>
                            </form>
                        </td>
                        <td>
                            <form method="post" class="inline">
                                <input type="hidden" name="action" value="delete_establishment">
                                <input type="hidden" name="id" value="<?= h((string) $establishment['id']) ?>">
                                <button type="submit" class="secondary">Supprimer</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($establishments === []): ?>
                    <tr><td colspan="2">Aucun établissement enregistré.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </section>
    <?php elseif ($page === 'import'): ?>
        <section class="panel">
            <h2>Importer une liste existante</h2>
            <p>Chargez un fichier texte, CSV ou une colonne de noms. Chaque ligne crée une visite sans date ni établissement si ces données ne sont pas connues.</p>
            <form method="post" enctype="multipart/form-data" class="stack">
                <input type="hidden" name="action" value="import_visitors">
                <label>Fichier à importer
                    <input type="file" name="visitors_file" required>
                </label>
                <button type="submit">Importer</button>
            </form>
        </section>
    <?php elseif ($page === 'preload'): ?>
        <?php $visits = $repository->listVisitsNeedingEnrichment(); ?>
        <section class="panel">
            <h2>Préchargement</h2>
            <p>Complétez les dates, les établissements manquants et tranchez les homonymes issus des imports ou des enregistrements partiels.</p>
        </section>
        <section class="panel">
            <table>
                <thead>
                    <tr>
                        <th>Personnalité</th>
                        <th>Informations</th>
                        <th>Mise à jour</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($visits as $visit): ?>
                    <tr>
                        <td>
                            <strong><?= h((string) $visit['display_name']) ?></strong><br>
                            <span class="muted"><?= h((string) ($visit['profession'] ?? 'À résoudre sur Wikipédia')) ?></span>
                        </td>
                        <td>
                            Date : <?= h(formatDate($visit['visited_on'] ?? null)) ?><br>
                            Établissement : <?= h((string) ($visit['establishment_name'] ?? 'À renseigner')) ?>
                        </td>
                        <td>
                            <form method="post" class="stack">
                                <input type="hidden" name="action" value="update_visit">
                                <input type="hidden" name="visit_id" value="<?= h((string) $visit['id']) ?>">
                                <input type="date" name="visited_on" value="<?= h((string) ($visit['visited_on'] ?? '')) ?>">
                                <select name="establishment_id">
                                    <option value="">Non renseigné</option>
                                    <?php foreach ($establishments as $establishment): ?>
                                        <option value="<?= h((string) $establishment['id']) ?>" <?= selected((int) $establishment['id'], $visit['establishment_id'] ?? null) ?>>
                                            <?= h((string) $establishment['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="submit">Mettre à jour</button>
                            </form>
                            <?php if (empty($visit['person_id'])): ?>
                                <p><a href="?page=preload&resolve=<?= h((string) $visit['id']) ?>">Résoudre l’homonyme</a></p>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php if ($candidateSelection !== null && ($candidateSelection['visit_id'] ?? null) === (int) $visit['id']): ?>
                        <tr>
                            <td colspan="3">
                                <?php if ($candidateSelection['candidates'] === []): ?>
                                    <p>Aucune correspondance Wikipédia trouvée pour cette visite.</p>
                                <?php else: ?>
                                    <form method="post" class="stack">
                                        <input type="hidden" name="action" value="resolve_visit_person">
                                        <input type="hidden" name="visit_id" value="<?= h((string) $visit['id']) ?>">
                                        <?php foreach ($candidateSelection['candidates'] as $index => $candidate): ?>
                                            <label class="choice">
                                                <input type="radio" name="wikidata_id" value="<?= h((string) $candidate['wikidata_id']) ?>" <?= $index === 0 ? 'checked' : '' ?>>
                                                <strong><?= h((string) $candidate['name']) ?></strong><br>
                                                <span class="muted"><?= h((string) ($candidate['profession'] ?? 'Profession inconnue')) ?></span><br>
                                                <span class="muted">Né(e) le : <?= h(formatDate($candidate['birth_date'] ?? null)) ?></span>
                                            </label>
                                        <?php endforeach; ?>
                                        <button type="submit">Associer la personnalité</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endif; ?>
                <?php endforeach; ?>
                <?php if ($visits === []): ?>
                    <tr><td colspan="3">Aucune visite à compléter.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </section>
    <?php endif; ?>
</main>
</body>
</html>
<?php
$content = (string) ob_get_clean();

echo $content;

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function selected(int $expected, mixed $current): string
{
    return (int) $current === $expected ? 'selected' : '';
}

function formatDate(?string $value): string
{
    if ($value === null || $value === '') {
        return 'Inconnue';
    }

    try {
        return (new DateTimeImmutable($value))->format('d/m/Y');
    } catch (Throwable) {
        return $value;
    }
}

function normalizeDate(mixed $value): ?string
{
    $value = trim((string) $value);

    if ($value === '') {
        return null;
    }

    $date = DateTimeImmutable::createFromFormat('Y-m-d', $value);

    if ($date === false || $date->format('Y-m-d') !== $value) {
        throw new RuntimeException('Date invalide.');
    }

    return $value;
}

function normalizeInteger(mixed $value): ?int
{
    if ($value === null || $value === '') {
        return null;
    }

    $integer = filter_var($value, FILTER_VALIDATE_INT);

    if ($integer === false || $integer < 1) {
        throw new RuntimeException('Valeur numérique invalide.');
    }

    return $integer;
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function consumeFlash(): ?array
{
    if (!isset($_SESSION['flash']) || !is_array($_SESSION['flash'])) {
        return null;
    }

    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);

    return $flash;
}

function redirect(string $location): never
{
    header('Location: ' . $location);
    exit;
}

function importVisitors(string $path, Repository $repository, WikipediaClient $wikipedia): array
{
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    if ($lines === false) {
        throw new RuntimeException('Fichier illisible.');
    }

    $total = 0;
    $resolved = 0;
    $pending = 0;

    foreach ($lines as $line) {
        $name = extractNameFromImportLine($line);

        if ($name === null) {
            continue;
        }

        $total++;
        $candidates = $wikipedia->searchPeople($name);

        if (count($candidates) === 1) {
            $personId = $repository->upsertPerson($candidates[0]);
            $repository->createVisit($name, $personId, null, null);
            $resolved++;
            continue;
        }

        $repository->createVisit($name, null, null, null);
        $pending++;
    }

    return [
        'total' => $total,
        'resolved' => $resolved,
        'pending' => $pending,
    ];
}

function extractNameFromImportLine(string $line): ?string
{
    $trimmed = trim($line);

    if ($trimmed === '') {
        return null;
    }

    $columns = str_contains($trimmed, ';')
        ? str_getcsv($trimmed, ';')
        : str_getcsv($trimmed);

    $name = trim((string) ($columns[0] ?? $trimmed));

    if ($name === '' || in_array(mb_strtolower($name), ['nom', 'name'], true)) {
        return null;
    }

    return $name;
}
