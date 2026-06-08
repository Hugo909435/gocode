# Cockpit — Mémoire projet

## Vision

Interface web mono-utilisateur, auto-hébergée, pour piloter un agent de code IA local ("Claude Code local" ou tout moteur compatible). Le Cockpit expose une UI riche permettant de créer des sessions de travail, d'envoyer des instructions à l'agent, de suivre son activité en temps réel (via SSE), de valider ses actions sensibles, et de consulter l'historique complet.

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

## Contrat AgentDriver

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

### Flux d'événements émis par l'agent

Chaque événement suit le schéma :

```json
{
  "type": "<event_type>",
  "session_id": "<uuid>",
  "timestamp": "<ISO 8601>",
  "payload": { ... }
}
```

| Type | Description |
|---|---|
| `status` | Changement d'état de l'agent (idle, thinking, working…) |
| `plan` | Plan d'action proposé par l'agent |
| `message` | Message textuel de l'agent vers l'utilisateur |
| `log` | Log interne de l'agent |
| `terminal` | Sortie terminal d'une commande exécutée |
| `tool_call` | Appel d'outil (lecture fichier, recherche…) |
| `file_change` | Modification de fichier avec diff unifié |
| `confirmation_request` | Demande de validation humaine obligatoire |
| `cost` | Coût estimé de la session (tokens / $) |
| `done` | Session terminée normalement |
| `error` | Erreur irrécupérable |

## Modèle de données (résumé)

| Table | Rôle |
|---|---|
| `users` | Compte unique du propriétaire du Cockpit |
| `projects` | Projets code pilotés (chemin local, repo Git…) |
| `sessions` | Sessions de travail agent (mode, statut, coût…) |
| `messages` | Flux d'événements persistés d'une session (type + payload JSON) |
| `command_whitelist` | Commandes shell autorisées par projet |
| `settings` | Configuration globale clé/valeur |

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

## Journal des décisions

| Date | Décision |
|---|---|
| 2026-06-08 | Initialisation du projet : Laravel + Sanctum + Vue 3 + Vite + Tailwind + Pint + ESLint/Prettier. Squelette de base sans feature métier. |
| 2026-06-08 | Architecture à 3 couches retenue (Frontend / Backend Laravel / Moteur agent). Interface `AgentDriverContract` définie pour isoler le moteur IA. MockDriver utilisé en développement. |
| 2026-06-08 | SSE choisi (vs WebSocket) pour la simplicité du déploiement mono-utilisateur auto-hébergé. |
| 2026-06-08 | Auth par cookie de session Sanctum (SPA same-origin) — pas de token API, plus simple et plus sûr pour un usage local. |
