# EduHub — Real-Time & Offline-Resilient Online Quiz Platform

[![Laravel](https://img.shields.io/badge/Laravel-11.x-FF2D20?style=flat&logo=laravel&logoColor=white)](https://laravel.com)
[![Svelte](https://img.shields.io/badge/Svelte-5.x-FF3E00?style=flat&logo=svelte&logoColor=white)](https://svelte.dev)
[![TypeScript](https://img.shields.io/badge/TypeScript-5.x-3178C6?style=flat&logo=typescript&logoColor=white)](https://www.typescriptlang.org)
[![TailwindCSS](https://img.shields.io/badge/TailwindCSS-3.x-38B2AC?style=flat&logo=tailwind-css&logoColor=white)](https://tailwindcss.com)

**EduHub** is an online examination and quiz management system designed for instructors and students. It combines interactive classroom quizzes with practical exam integrity and connection resilience.

🌐 **Live Demo:** [https://quizportal.me/](https://quizportal.me/)

---

## Why EduHub? (Core Strengths)

- **Offline-Resilient Student Experience**  
  Built with **Svelte 5** and client-side IndexedDB (**Dexie.js**), active quiz state is cached locally in real time. If a student experiences an unexpected network drop or page reload, their progress and answered questions are preserved. Pending submissions are automatically queued and synced when reconnected.

- **Per-Question Countdown Timers**  
  Each question features its own dedicated countdown timer and strict client-server pacing, ensuring students cannot stall and exams progress smoothly.

- **Instructor Room & Schedule Management**  
  Every instructor has their own dedicated room and dashboard. Teachers can create questions with rich text and image attachments, set custom timers, schedule automated release times, or start and stop quizzes on demand.

- **Leaderboards, Analytics & PDF Export**  
  After quizzes conclude, instructors and students get instant score breakdowns, visual performance graphs powered by Chart.js, and downloadable PDF leaderboards for grading records.

---

## Feature Summary

| Area | Features |
| :--- | :--- |
| **Instructor Dashboard** | Secure authentication, room management, quiz creation & question editor, image attachments, schedule management, instant manual start/stop. |
| **Student Experience** | Fast single-page interface (Svelte), randomized question delivery, per-question timers, responsive UI on mobile & desktop. |
| **Offline Architecture** | IndexedDB local storage (`Dexie.js`) for active quiz state, answer queue, and automatic background retry/sync. |
| **Integrity & Security** | Hidden answers on the client until grading. |
| **Results & Reporting** | Real-time leaderboards, performance distribution charts (`Chart.js`), student result review, and PDF report export (`DomPDF`). |

---

## Tech Stack

- **Backend:** PHP 8.2+, Laravel 11, Laravel Sanctum, Barryvdh DomPDF
- **Frontend:** Svelte 5, TypeScript, Blade Templates, Tailwind CSS, Bootstrap, Animate.css
- **Client Storage & State:** IndexedDB (Dexie.js), LocalStorage
- **Data Visualization & Date Pickers:** Chart.js, Flatpickr
- **Build Tool:** Vite

---

## Getting Started

### Prerequisites

Ensure you have the following installed on your machine:
- PHP >= 8.2
- Composer
- Node.js >= 18 & npm
- MySQL or SQLite

### Local Installation

1. **Clone the repository:**
   ```bash
   git clone https://github.com/your-username/quiz-app.git
   cd quiz-app
   ```

2. **Install PHP and JavaScript dependencies:**
   ```bash
   composer install
   npm install
   ```

3. **Set up environment configuration:**
   ```bash
   cp .env.example .env
   php artisan key:generate
   ```

4. **Configure your database and mail settings in `.env`:**
   ```env
   DB_CONNECTION=mysql
   DB_HOST=127.0.0.1
   DB_PORT=3306
   DB_DATABASE=quiz_app
   DB_USERNAME=root
   DB_PASSWORD=

   # Email settings
   MAIL_MAILER=smtp
   MAIL_HOST=smtp.mailtrap.io
   MAIL_PORT=2525
   MAIL_USERNAME=your_username
   MAIL_PASSWORD=your_password
   ```

5. **Run database migrations:**
   ```bash
   php artisan migrate
   ```

6. **Start the local development server:**
   ```bash
   # In terminal 1 (Laravel server)
   php artisan serve

   # In terminal 2 (Vite frontend asset compiler)
   npm run dev
   ```

   Or run concurrently:
   ```bash
   composer run dev
   ```

7. Open [http://localhost:8000](http://localhost:8000) in your browser.

---

## Live Deployment

The application is deployed and accessible at:  
👉 **[https://quizportal.me/](https://quizportal.me/)**

---

## Docker Deployment

Production images are built in CI and published to GitHub Container Registry. The
Azure VM runs the image with `docker-compose.production.yml`, including a
persistent MySQL container.

On the VM, create `/var/www/quiz-app/.env` with the Laravel and MySQL settings:

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://your-domain.example
APP_KEY=base64:replace-with-a-production-key

DB_CONNECTION=mysql
DB_HOST=mysql
DB_PORT=3306
DB_DATABASE=quiz_app
DB_USERNAME=quiz_app
DB_PASSWORD=replace-with-a-secret

MYSQL_DATABASE=quiz_app
MYSQL_USER=quiz_app
MYSQL_PASSWORD=replace-with-a-secret
MYSQL_ROOT_PASSWORD=replace-with-a-different-secret

MAIL_MAILER=log
QUEUE_CONNECTION=database
SESSION_DRIVER=database
CACHE_STORE=database
```

The VM also needs Docker, the Compose plugin, and a GitHub Container Registry
read token configured as `GHCR_USERNAME` and `GHCR_READ_TOKEN` in the deployment
workflow secrets. The VM deploy directory should contain
`docker-compose.production.yml`, `docker/`, and `.env`.

The `DB_HOST=mysql` value is the Docker Compose service name. Laravel, the queue
worker, and the scheduler reach MySQL over the private Compose network; MySQL is
not exposed on a host port.

The deployment performs these steps:

```bash
docker compose -f docker-compose.production.yml pull
docker compose -f docker-compose.production.yml run --rm app php artisan migrate --force
docker compose -f docker-compose.production.yml up -d --remove-orphans
```

Database data is stored in the `mysql_data` Docker volume, and uploaded files are
stored in the `app_storage` Docker volume. Back up both volumes before treating
the VM as the only copy of production data.

---
