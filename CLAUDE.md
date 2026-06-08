# gocode — Mémoire projet

## Vision

Interface web mono-utilisateur, auto-hébergée, pour piloter un agent de code IA local ("Claude Code local" ou tout moteur compatible). Le gocode expose une UI riche permettant de créer des sessions de travail, d'envoyer des instructions à l'agent, de suivre son activité en temps réel (via SSE), de valider ses actions sensibles, et de consulter l'historique complet.

## Stack

| Couche | Technologie |
|---|---|
| Backend | Laravel (dernière version stable), API REST + SSE |
| Frontend | Vue 3 (Composition API) + Vite + Tailwind CSS |
| Base de données | MySQL |
| Auth | Mono-utilisateur, Laravel Sanctum (mode SPA, cookie de session, même origine) |
| Temps réel | SSE (Server-Sent Events) — pas de WebSocket |
| Qualité PHP | Laravel Pint |
| Qualité JS | ESLint + Prettier |

## Architecture cible

```
┌─────────────────────────────────────┐
│  Frontend (Vue 3 SPA / Vite)        │
│  Vue Router · Pinia · Tailwind      │
└──────────────┬──────────────────────┘
               │ HTTP/SSE (même origine)
┌──────────────▼──────────────────────┐
│  Backend Laravel                    │
│  API REST /api                      │
│  SSE endpoint /api/sessions/{id}/stream │
│  Sanctum (cookie session)           │
│  Git integration                    │
│  AgentDriver (orchestration)        │
└──────────────┬──────────────────────┘
               │ interface pluggable
┌──────────────▼──────────────────────┐
│  Moteur agent externe               │
│  → MockDriver (développement)       │
│  → OpenCodeDriver (production)      │
└─────────────────────────────────────┘
```

## Couche Agent

### Contrat (`App\Contracts\AgentDriverContract`)

Interface PHP qui abstrait complètement le moteur IA. Toute la logique d'orchestration passe par elle.

```php
interface AgentDriverContract
{
    public function startSession(Session $session): void;

    public function sendInstruction(
        Session $session,
        string $instruction,
        string $mode // 'read' | 'plan' | 'execute'
    ): void;

    public function stop(Session $session): void;
}
```

### Événements typés

| Classe | Rôle |
|---|---|
| `App\Agent\AgentEventType` | Enum backed string — 11 cas |
| `App\Agent\AgentEvent` | DTO readonly : `type`, `sessionId`, `timestamp`, `payload[]` |
| `App\Agent\Events\AgentEventDispatched` | Laravel event wrappant un `AgentEvent` |

#### Wire format d'un événement

```json
{
  "type": "<event_type>",
  "session_id": "<uuid>",
  "timestamp": "<ISO 8601>",
  "payload": { ... }
}
```

| Type (`AgentEventType`) | Description | `messages.type` en DB | `messages.role` |
|---|---|---|---|
| `status` | Changement d'état (idle, thinking…) | `status` | `system` |
| `plan` | Plan d'action proposé | `plan` | `agent` |
| `message` | Message textuel vers l'utilisateur | `text` | `agent` |
| `log` | Log interne | `log` | `system` |
| `terminal` | Sortie terminal | `terminal` | `tool` |
| `tool_call` | Appel d'outil | `tool_call` | `tool` |
| `file_change` | Diff unifié d'un fichier | `file_change` | `tool` |
| `confirmation_request` | Validation humaine requise | `confirmation_request` | `system` |
| `cost` | Coût estimé (tokens/$) | `cost` | `system` |
| `done` | Fin normale de session | `status` (payload done) | `system` |
| `error` | Erreur irrécupérable | `error` | `system` |

### AgentEventDispatcher (`App\Agent\AgentEventDispatcher`)

Double rôle à chaque `dispatch(AgentEvent)` :

1. **Persistance** — insère un `Message` dans la table `messages` (mapping type/role ci-dessus).
2. **Publication locale** — fire `AgentEventDispatched` (Laravel event synchrone, pour futurs listeners).

**Canal SSE** : l'endpoint SSE lit `messages WHERE session_id = ? AND id > :cursor ORDER BY id`
avec un court intervalle. Choix délibéré : **polling DB** plutôt que Redis/Pusher — zéro
infrastructure supplémentaire pour un déploiement mono-utilisateur auto-hébergé.

### AgentManager (`App\Agent\AgentManager`)

Factory singleton enregistrée dans `AppServiceProvider`. Instancie le driver selon `config('agent.default')`.
`AgentDriverContract` est lié via `app()->bind(...)` → `AgentManager::driver()`.

```php
// Depuis n'importe quel service :
app(AgentDriverContract::class)->sendInstruction($session, $instruction, $mode);
// ou :
app(AgentManager::class)->driver('mock')->startSession($session);
```

### config/agent.php

| Clé | Valeur par défaut | Env |
|---|---|---|
| `default` | `mock` | `AGENT_DRIVER` |

### Drivers disponibles

| Slug | Classe | Environnement |
|---|---|---|
| `mock` | `App\Services\Agent\MockDriver` | Développement |
| _(à venir)_ | `OpenCodeDriver` | Production |

## Modèle de données

| Table | Rôle |
|---|---|
| `users` | Compte unique du propriétaire du gocode |
| `projects` | Projets code pilotés (chemin local, repo Git…) |
| `agent_sessions` | Sessions de travail agent (nommée `agent_sessions` pour éviter le conflit avec la table `sessions` du driver de session Laravel) |
| `messages` | Flux d'événements persistés d'une session |
| `command_whitelist` | Commandes shell autorisées par projet (ou globales si `project_id` NULL) |
| `settings` | Configuration globale clé/valeur JSON |

### `projects`

| Colonne | Type | Notes |
|---|---|---|
| `id` | bigint PK auto-increment | |
| `name` | string | |
| `path` | string | Chemin absolu du dépôt sur le serveur |
| `default_branch` | string | Défaut : `main` |
| `stack` | string nullable | Ex. `Laravel/Vue`, `Next.js` |
| `description` | text nullable | |
| `git_remote` | string nullable | URL du remote Git |
| `metadata` | json nullable | Données libres supplémentaires |
| `created_at` / `updated_at` | timestamps | |

### `agent_sessions`

| Colonne | Type | Notes |
|---|---|---|
| `id` | uuid PK | |
| `project_id` | FK → projects | cascade delete |
| `title` | string nullable | |
| `mode` | enum | `read` / `plan` / `execute` — défaut `read` |
| `status` | enum | `idle` / `reading` / `planning` / `awaiting_confirmation` / `building` / `running` / `done` / `error` — défaut `idle` |
| `initial_instruction` | text nullable | Instruction d'ouverture de session |
| `input_tokens` | uint | Tokens consommés en entrée |
| `output_tokens` | uint | Tokens générés |
| `cost_usd` | decimal(10,6) | Coût estimé |
| `started_at` / `ended_at` | timestamp nullable | |
| `created_at` / `updated_at` | timestamps | |

### `messages`

| Colonne | Type | Notes |
|---|---|---|
| `id` | bigint PK auto-increment | |
| `session_id` | FK uuid → agent_sessions | cascade delete |
| `role` | enum | `user` / `agent` / `system` / `tool` |
| `type` | enum | `text` / `plan` / `log` / `terminal` / `tool_call` / `file_change` / `confirmation_request` / `cost` / `status` / `error` |
| `content` | longText | Contenu brut ou JSON sérialisé (ex. diff unifié) |
| `meta` | json nullable | Métadonnées associées (ex. `{"file": "...", "additions": 2}`) |
| `created_at` / `updated_at` | timestamps | Index composé sur `(session_id, created_at)` |

### `command_whitelist`

| Colonne | Type | Notes |
|---|---|---|
| `id` | bigint PK auto-increment | |
| `project_id` | FK nullable → projects | NULL = règle globale |
| `pattern` | string | Ex. `php artisan *`, `git diff *` |
| `description` | string nullable | |
| `created_at` / `updated_at` | timestamps | |

### `settings`

| Colonne | Type | Notes |
|---|---|---|
| `key` | string PK | Identifiant unique |
| `value` | json nullable | Valeur sérialisée en JSON |
| `created_at` / `updated_at` | timestamps | |

## Garde-fous

- **Confirmation humaine obligatoire** avant tout `git commit` et avant tout `git push`.
- **Whitelist de commandes shell** : l'agent ne peut exécuter que des commandes explicitement autorisées par projet.
- **3 modes de session** :
  - `read` : lecture seule, aucune modification de fichier ni exécution.
  - `plan` : l'agent peut proposer un plan mais n'exécute rien.
  - `execute` : exécution complète sous supervision (confirmations requises pour les actions sensibles).

## Conventions

- **Code** : anglais (noms de variables, méthodes, classes, routes).
- **Commentaires** : français accepté pour expliquer le "pourquoi".
- **API** : RESTful sous `/api`, réponses JSON systématiques.
- **Validation** : Form Requests Laravel (jamais dans le contrôleur).
- **Sérialisation** : API Resources Laravel (jamais de `$model->toArray()` brut en réponse).
- **Logique métier** : Services dédiés dans `app/Services/` — les contrôleurs orchestrent, ils ne calculent pas.
- **SSE** : endpoint dédié par session, keepalive toutes les 15 s, reconnexion auto côté client.

## SSE — Détails d'implémentation

### Endpoint

`GET /api/sessions/{session}/stream` — protégé par `auth:sanctum` (cookie de session, même mécanisme que les autres routes).

### Format d'un événement

```
id: <message.id>
event: <event_type>
data: {"type":"...","session_id":"...","timestamp":"...","payload":{...}}

```

- `id` : ID bigint de la ligne `messages` — sert de curseur pour la reconnexion.
- `event` : type original de l'`AgentEvent` (`done`, `error`, `message`, `plan`, `log`…).
- `data` : wire format JSON reconstruit depuis la DB (`meta.event_type` + `meta.timestamp` + `content` décodé).

### Reconnexion automatique

Le navigateur renvoie `Last-Event-ID: <dernier_id_reçu>` lors d'une reconnexion. L'endpoint reprend alors le polling depuis `WHERE messages.id > Last-Event-ID`. Sans ce header, le flux repart de 0 (replay complet).

### Fermeture du flux

- Événement `done` ou `error` reçu → le serveur clos le flux immédiatement après émission.
- Session déjà terminale mais sans message terminal en base (cas dégradé) → commentaire `: stream-closed` puis fermeture.

### Pièges buffering

| Couche | Solution appliquée |
|---|---|
| PHP gzip (`zlib.output_compression`) | `@ini_set('zlib.output_compression', 'Off')` dans le callback |
| PHP SAPI output buffer | `flush()` après chaque événement |
| nginx proxy buffer | Header `X-Accel-Buffering: no` |
| Apache mod_deflate | Ajouter `Header set Content-Encoding identity` dans la config vhost/`.htaccess` |
| PHP userspace ob levels (middleware, tests) | Pas d'`ob_flush()` — `flush()` vide uniquement le buffer SAPI ; les ob levels actifs (ex. capture de test via `streamedContent()`) restent intacts |

> **Auth SSE** : `EventSource` envoie les cookies automatiquement. Côté Sanctum, la route est dans le groupe `auth:sanctum`, donc le cookie de session est vérifié comme pour n'importe quelle route API. Côté CORS, `supports_credentials: true` est requis pour les connexions cross-origin (dev Vite sur :5173).

## Authentification

### Flux complet (SPA + SSE)

```
1. SPA → GET /sanctum/csrf-cookie
   Sanctum pose deux cookies : session (HttpOnly) + XSRF-TOKEN (lisible par JS)

2. SPA → POST /api/login  { email, password }
   Header : X-XSRF-TOKEN: <valeur du cookie XSRF-TOKEN>
   ← 200 { "data": { "id", "name", "email" } }  +  cookie de session régénéré

3. Requêtes API protégées → auth:sanctum vérifie le cookie de session
   GET /api/me  ←  200 { "data": { ... } }

4. Connexions SSE → new EventSource('/api/sessions/{id}/stream', { withCredentials: true })
   Le navigateur joint automatiquement le cookie de session.
   Le CORS (supports_credentials: true) autorise la réponse cross-origin en dev Vite.

5. SPA → POST /api/logout
   ← 200 { "message": "Logged out" }  +  session invalidée
```

### Points clés de la configuration

| Élément | Valeur | Pourquoi |
|---|---|---|
| `SESSION_DRIVER` | `database` | Persistance fiable, pas de fichiers sur disque |
| `SESSION_DOMAIN` | `localhost` | Partage du cookie entre :5173 (Vite) et :8000 (Laravel) |
| `SESSION_SAME_SITE` | `lax` | Compatible SPA same-origin ; passer à `none` + `secure` si cross-origin strict |
| `SANCTUM_STATEFUL_DOMAINS` | `localhost:5173,localhost:8000` | Domaines SPA reconnus par Sanctum pour le mode cookie |
| `cors.supports_credentials` | `true` | Obligatoire pour que les cookies soient transmis (CORS + SSE) |
| `cors.allowed_origins` | `FRONTEND_URL` + `APP_URL` | `*` interdit dès que credentials sont activés |

### Compte administrateur (seeder)

Variables `.env` à définir **avant** `php artisan db:seed` :

```dotenv
ADMIN_EMAIL=admin@gocode.local   # identifiant de connexion
ADMIN_PASSWORD=password           # à changer impérativement
```

Le seeder utilise `firstOrCreate` : re-jouer le seed ne duplique pas le compte.

## Journal des décisions

| Date | Décision |
|---|---|
| 2026-06-08 | Initialisation du projet : Laravel + Sanctum + Vue 3 + Vite + Tailwind + Pint + ESLint/Prettier. Squelette de base sans feature métier. |
| 2026-06-08 | Architecture à 3 couches retenue (Frontend / Backend Laravel / Moteur agent). Interface `AgentDriverContract` définie pour isoler le moteur IA. MockDriver utilisé en développement. |
| 2026-06-08 | SSE choisi (vs WebSocket) pour la simplicité du déploiement mono-utilisateur auto-hébergé. |
| 2026-06-08 | Auth par cookie de session Sanctum (SPA same-origin) — pas de token API, plus simple et plus sûr pour un usage local. |
| 2026-06-08 | Implémentation auth : LoginRequest (Form Request), UserResource (API Resource), endpoints POST /api/login + POST /api/logout + GET /api/me. Credentials admin configurables via ADMIN_EMAIL/ADMIN_PASSWORD dans .env. CORS configuré avec supports_credentials: true pour SSE cross-origin en dev Vite. |
| 2026-06-08 | Modèle de données complet : migrations, modèles Eloquent avec relations bidirectionnelles, factories (Project/Session/Message), seeder dev avec données de démo. Table `sessions` nommée `agent_sessions` pour éviter le conflit avec le driver de session Laravel. `messages.content` en longText (peut contenir du JSON), `messages.meta` en JSON nullable pour les métadonnées structurées. |
| 2026-06-08 | Couche Agent implémentée : `AgentEventType` (enum), `AgentEvent` (DTO readonly), `AgentEventDispatcher` (persist + fire Laravel event), `AgentManager` (factory), `MockDriver` (émet des événements réalistes). Canal SSE = polling DB sur `messages` avec curseur — choix délibéré pour éviter Redis sur un déploiement mono-utilisateur. `config/agent.php` avec `AGENT_DRIVER=mock` par défaut. `AgentDriverContract` lié dans `AppServiceProvider` via `AgentManager`. |
| 2026-06-08 | `MockDriver` remplacé par `App\Agent\Drivers\MockAgentDriver` + `App\Jobs\MockAgentJob`. Simulation réaliste via job en file d'attente avec `usleep()` entre événements (QUEUE_CONNECTION=database + queue:work). Séquences par mode : `read` (message texte + done), `plan` (plan md + confirmation_request « Valider ? »), `execute` (log/terminal/tool_call/file_change/cost + confirmation_request « Committer ? » → resume job → done). Deux scénarios paramétrables : `success` et `error` (env MOCK_SCENARIO). `confirmAction()` ajouté au contrat `AgentDriverContract`, état de confirmation stocké dans le Cache Laravel (clé `mock.pending.{sessionId}`). Commande `php artisan agent:demo {session} [--scenario=success\|error] [--mode=read\|plan\|execute] [--no-delay]` exécute le scénario synchronement sans queue worker. |
| 2026-06-08 | Endpoint SSE `GET /api/sessions/{session}/stream` implémenté dans `SessionStreamController`. Polling DB sur `messages WHERE id > cursor` toutes les 500 ms. Reconnexion via `Last-Event-ID`. Keepalive `: keepalive` toutes les 15 s pendant les périodes creuses. Fermeture automatique sur événement `done`/`error` ou session terminale sans message terminal. Pièges buffering : `zlib.output_compression Off` + `flush()` (SAPI) + `X-Accel-Buffering: no` (nginx). Pas d'`ob_flush()` pour préserver la compatibilité avec la capture de tests (`streamedContent()`). 7 tests Feature dans `SessionStreamTest`. |
| 2026-06-08 | Cycle de vie des sessions exposé en API REST : `GET /api/projects/{project}/sessions`, `POST /api/projects/{project}/sessions`, `GET /api/sessions/{session}`, `POST /api/sessions/{session}/instruction`, `POST /api/sessions/{session}/confirm`, `POST /api/sessions/{session}/stop`, `PATCH /api/sessions/{session}`. `SessionService` orchestre la logique métier (création, envoi instruction, confirmation, stop, update). Message utilisateur persisté avant appel driver. `SessionResource` + `MessageResource` (décodage JSON du contenu). `sendInstruction` met à jour le mode de la session si fourni. 9 tests Feature couvrent le cycle complet dont un test execute-mode avec QUEUE_CONNECTION=sync + delay_ms=0. |
| 2026-06-08 | `GitService` implémenté (`app/Services/GitService.php`) avec 4 méthodes lecture seule : `status` (parse `git status --porcelain`), `diff` (diff unifié vs HEAD, fichier optionnel), `currentBranch` (`git rev-parse --abbrev-ref HEAD`), `log` (commits avec hash/short_hash/message/auteur/email/date). Exécution via Symfony Process (tableau de commandes, pas d'interpolation shell) toujours dans `$project->path`. `assertGitRepo` vérifie l'existence du dossier et le dépôt git avant chaque commande. 4 endpoints REST : `GET /api/projects/{project}/git/status|diff|branch|log`. `DiffRequest` valide le paramètre `?file=` (rejette chemins absolus et `..`). `LogRequest` valide `?limit=`. `GitController` gère les erreurs de configuration (path invalide/pas un dépôt) en 422. 13 tests Feature dans `tests/Feature/Git/GitControllerTest.php` sur un dépôt git temporaire réel. |
