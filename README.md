# hall-of-fame

Application PHP légère pour gérer la liste des personnalités ayant visité les établissements dans lesquels j'ai travaillé.

## Fonctionnalités

- consultation de la liste des visites avec tri par date, établissement ou nom ;
- affichage du nom, de la profession, de la date de visite, de l’établissement et d’une photo miniature ;
- gestion de la liste des établissements ;
- enregistrement d’une visite avec recherche Wikipédia/Wikidata sur le nom de la personnalité ;
- résolution des homonymes à partir de la profession et de la date de naissance ;
- import d’une liste existante et page de préchargement pour compléter les données manquantes.

## Lancement local

L’application démarre sans dépendance externe.

```bash
php -S 127.0.0.1:8000 -t /home/runner/work/hall-of-fame/hall-of-fame
```

Par défaut, une base SQLite locale est créée dans `var/hall-of-fame.sqlite` pour faciliter les essais.

## Déploiement AlwaysData / PostgreSQL

Configurer les variables d’environnement suivantes pour utiliser PostgreSQL :

- `DB_DSN` — par exemple `pgsql:host=...;port=5432;dbname=...`
- `DB_USER`
- `DB_PASSWORD`

Le schéma est créé automatiquement au premier chargement.
