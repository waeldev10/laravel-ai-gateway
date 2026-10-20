# Laravel AI Getway

Laravel 13 application (PHP >= 8.3).

AI-powered chat gateway designed to provide a central backend for integrating AI models and multiple clients and channels.

---

## Agent Instructions

This file is the entry point for AI agents working on this project.

Before modifying code:

1. Read this file.
2. Read `agent-skills/README.md`.
3. Read all skills relevant to the current phase.
4. Inspect the existing code before making changes.
5. Propose the implementation scope and wait for human confirmation.
6. Implement only the approved phase.

---

## Project Overview

The project is a Laravel-based AI Gateway.

The Laravel application acts as the central backend between clients/channels and AI providers.

Expected clients and channels include:

- Laravel Web Chat
- React/Next.js clients
- Telegram
- WhatsApp

Expected AI providers include:

- OpenAI
- Gemini
- Other providers when required

The project is developed incrementally through the plans defined in:

`plans/`

Do not implement future plans early.

---

## Commands

```sh
composer setup
# Install dependencies, configure .env, generate application key,
# run migrations, install npm dependencies, and build assets.

composer test
# Clear configuration cache and run the test suite.

composer dev
# Start the Laravel development environment.

npm run build
# Build frontend assets for production.

npm run dev
# Start the Vite development server.

vendor/bin/pint
# Run Laravel Pint.