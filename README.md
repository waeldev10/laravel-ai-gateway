# Laravel AI Hub

A Laravel-based AI Gateway for integrating AI models into applications through a centralized backend.

Laravel AI Hub provides a central backend for managing AI conversations and connecting applications and communication channels with different AI providers.

## Requirements

Before installing Laravel AI Hub, make sure you have the following installed:

- PHP >= 8.3
- Composer
- Node.js
- NPM
- MySQL
- Git

## Installation

### 1. Clone the repository

```bash
git clone https://github.com/your-username/laravel-ai-gateway.git
cd laravel-ai-gateway
2. Install PHP dependencies
composer install
3. Install JavaScript dependencies
npm install
4. Configure the environment

Copy the example environment file:

cp .env.example .env

Generate the Laravel application key:

php artisan key:generate
5. Configure the database

Create a MySQL database:

CREATE DATABASE laravel_ai_gateway;

Then configure your database credentials in .env:

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=laravel_ai_gateway
DB_USERNAME=root
DB_PASSWORD=

Update the username and password according to your local MySQL configuration.

6. Run database migrations
php artisan migrate
7. Configure AI providers

Add the API keys for the AI providers you want to use to your .env file.

OPENAI_API_KEY=
GEMINI_API_KEY=

Never commit API keys or other sensitive credentials to Git.

AI provider configuration will be updated as provider integrations are implemented.

8. Install and build frontend assets

For a production build:

npm run build

For frontend development:

npm run dev
Running the Application

Start the Laravel development server:

php artisan serve

The application will be available at:

http://127.0.0.1:8000

For local development, you can also use:

composer dev
Testing

Laravel AI Hub uses Pest for testing.

Run the test suite:

composer test

Or:

php artisan test

Run a specific test:

php artisan test --filter="test name"

Or:

vendor/bin/pest --filter="test name"
Code Style

The project uses Laravel Pint for code formatting.

Run:

vendor/bin/pint
Architecture

Laravel AI Hub acts as a centralized backend between applications, communication channels, and AI providers.

Laravel Web Chat
React / Next.js
Telegram
WhatsApp
       │
       ▼
Laravel AI Hub
       │
       ▼
   AI Gateway
       │
   ┌───┴───┐
   ▼       ▼
OpenAI   Gemini

The goal is to keep AI-related logic centralized instead of implementing separate AI integrations in every client.

Project Structure
laravel-ai-gateway/
├── app/
│   ├── Http/
│   ├── Models/
│   └── Services/
├── bootstrap/
├── config/
├── database/
│   ├── factories/
│   ├── migrations/
│   └── seeders/
├── public/
├── resources/
│   ├── css/
│   ├── js/
│   └── views/
├── routes/
├── storage/
├── tests/
│   ├── Feature/
│   └── Unit/
├── composer.json
├── package.json
└── README.md
Roadmap

The project is being developed incrementally.

 Laravel Web Chat
 AI Provider Integration
 Streaming
 REST API
 React / Next.js Client
 Telegram Bot
 WhatsApp Integration
Contributing

Contributions are welcome.

Before submitting a pull request:

Make sure your changes are focused.
Add or update tests where necessary.
Run the test suite.
Run Laravel Pint.
Update the documentation when necessary.

Run:

composer test

Then:

vendor/bin/pint

Please avoid unrelated changes in pull requests.

Security

Do not commit sensitive information such as:

API keys
Passwords
Access tokens
Private credentials
Production .env files

If you discover a security vulnerability, please report it privately rather than opening a public issue containing sensitive information.

License

Laravel AI Hub is open-source software licensed under the terms specified in the LICENSE file.