# TestGramatikov – Документация и Анализ

**TestGramatikov** е уеб-базирана платформа за създаване, управление и провеждане на образователни тестове. Поддържа две роли: **Учители** и **Ученици**. Изградена е с PHP 7.4+, PDO (MySQL/MariaDB) и Bootstrap 5.

---

## Съдържание

1. [Технически стек](#технически-стек)
2. [Структура на проекта](#структура-на-проекта)
3. [Архитектура на базата данни](#архитектура-на-базата-данни)
4. [Анализ на файловете](#анализ-на-файловете)
5. [Жизнен цикъл на тест](#жизнен-цикъл-на-тест)
6. [Система за оценяване](#система-за-оценяване)
7. [Изисквания за стартиране](#изисквания-за-стартиране)
8. [Препоръки за подобрения](#препоръки-за-подобрения)

---

## Технически стек

| Слой | Технология |
|---|---|
| Сървър | Apache (XAMPP) / Nginx |
| Backend | PHP 7.4+ |
| База данни | MySQL 5.7+ / MariaDB 10.3+ |
| ORM / DB достъп | PDO (Singleton pattern, lazy init) |
| Frontend | Bootstrap 5.3, Bootstrap Icons 1.11 |
| AI | Google Gemini 2.0 Flash (`api/generate_questions.php`) |
| Excel импорт | `lib/SimpleXLSX.php` (трета страна) |
| Теми | Светла / Тъмна (localStorage + Bootstrap data-bs-theme) |
| Tutorial | Driver.js (само за ученици) |

---

## Структура на проекта

```
TestGramatikov/
├── api/
│   └── generate_questions.php   # AI endpoint (Gemini)
├── assets/
│   ├── css/theme.css            # Глобални стилове + CSS токени
│   └── js/tutorial.js           # Driver.js туториал за ученици
├── components/
│   ├── header.php               # Навигация, тема, profile dropdown
│   ├── footer.php               # Футър + Bootstrap JS
│   └── test_styles.php          # CSS специфичен за тестов UI
├── db/
│   ├── schema.sql               # Пълна схема (DROP + CREATE)
│   └── migrations/
│       ├── 001_subjects_scoped.sql   # Добавя owner_teacher_id към subjects
│       └── 002_test_logs.sql         # Таблица за антишийт логове
├── lib/
│   ├── helpers.php              # Помощни функции (grade, percent, format_date)
│   ├── AssignmentFactory.php    # Логика за създаване на задания
│   ├── SimpleXLSX.php           # Excel парсер (трета страна)
│   └── debug_helper.php         # Дебъг утилита
├── uploads/                     # Медии към въпроси (снимки)
│── config.php                   # DB конфиг, PDO singleton, schema migrations
├── index.php                    # Начална страница
├── login.php                    # Вход
├── register.php                 # Регистрация
├── logout.php                   # Изход (session_destroy)
├── forgot_password.php          # Заявка за нулиране на парола
├── reset_password.php           # Форма за нова парола
├── dashboard.php                # Начален екран за учители и ученици
├── tests.php                    # Каталог с тестове
├── createTest.php               # Създаване/редакция на тест (3-стъпков wizard)
├── test_edit.php                # Обвивка за редактиране
├── test_view.php                # Преглед/изпълнение на тест
├── test_log_event.php           # API endpoint за логване на действия
├── assignments.php              # Списък активни/архивни задания
├── assignments_create.php       # Създаване/редакция на задание
├── assignment.php               # Детайли на задание
├── assignment_overview.php      # Мониторинг – кой как е решил
├── assignment_delete.php        # Изтриване на задание
├── attempt_review.php           # Ръчно оценяване от учителя
├── attempt_delete.php           # Изтриване на опит
├── student_attempt.php          # Резултат от опит за ученика
├── my_attempts.php              # История на опитите за ученика
├── grading_batch.php            # Групово оценяване
├── classes_create.php           # Управление на класове
├── join_class.php               # Присъединяване към клас с код
├── subjects_create.php          # Управление на предмети
├── categories.php               # Публичен списък с категории
├── students_search.php          # Търсене на ученици (AJAX helper)
├── teacher_actions.php          # Действия с учителски профил
├── download_template.php        # Сваляне на Excel шаблон
├── backToTop.js                 # Back-to-top бутон
├── shortlyq_testgramatikov.sql  # DB дъмп (backup)
└── verify_schema.php / debug_*.php  # Дебъг/верификационни скриптове
```

---

## Архитектура на базата данни

### Основни таблици (11 таблици + 4 VIEW-та)

```
users ──────────────────────────────────────────────────────────┐
  │ role: teacher | student                                       │
  │                                                               │
  ├─── password_resets (selector/token-hash парадигма)           │
  │                                                               │
  ├─── classes (teacher_id FK)                                   │
  │      └─── class_students (class_id, student_id FK)           │
  │                                                               │
  ├─── subjects (owner_teacher_id FK, nullable)                  │
  │                                                               │
  ├─── tests (owner_teacher_id, subject_id FK)                   │
  │      └─── test_questions (test_id, question_id FK)           │
  │                  │                                            │
  │            question_bank (owner_teacher_id FK) ──────────────┘
  │                  └─── answers (question_id FK)
  │
  └─── assignments (test_id, assigned_by_teacher_id FK)
         ├─── assignment_classes (assignment_id, class_id FK)
         ├─── assignment_students (assignment_id, student_id FK)
         └─── attempts (assignment_id, test_id, student_id FK)
                ├─── attempt_answers (attempt_id, question_id FK)
                └─── test_logs (attempt_id, student_id FK)  ← антишийт
```

### Аналитични изгледи (VIEWs)

| View | Предназначение |
|---|---|
| `v_student_overview` | Среден успех, брой задания, последна активност на ученик |
| `v_class_assignment_stats` | Ср. % по клас и задание |
| `v_cohort_assignment_stats` | Статистика за випуск (клас + учебна година) |
| `v_question_stats` | Брой опити, ср. точки, % верни отговори по въпрос |

### Колони добавяни динамично (в `config.php`)

Заради backward compatibilty, `config.php` съдържа функции `ensure_*()`, които при първо зареждане добавят нови колони, ако ги няма:

| Функция | Добавя |
|---|---|
| `ensure_subjects_scope()` | `subjects.owner_teacher_id` |
| `ensure_attempts_grade()` | `attempts.teacher_grade`, `attempts.strict_violation` |
| `ensure_class_invite_token()` | `classes.join_token` |
| `ensure_test_theme_and_q_media()` | `tests.is_strict_mode`, `tests.theme`, `tests.theme_config`, `question_bank.media_url`, `question_bank.media_mime` |
| `ensure_test_logs_table()` | Таблицата `test_logs` + колона `meta` |
| `ensure_password_resets_table()` | Таблицата `password_resets` |
| `ensure_assignment_tokens()` | `assignment_classes.access_token` |

---

## Анализ на файловете

### `config.php` (506 реда)
**Роля:** Централен конфигурационен файл.
- DB credentials (лесно заменими с env vars).
- `db()` – PDO singleton с lazy инициализация.
- `generate_token()` – криптографски сигурен токен за резет на парола.
- `send_app_mail()` – PHP mail() обвивка (не SMTP).
- `app_url()` – динамично изчислява base URL.
- `sanitize_redirect_path()` – предотвратява open redirect атаки.
- `log_test_event()` – вписва действия на ученик в `test_logs`.
- Множество `ensure_*()` схема миграции при runtime.

**Проблеми:**
- DB паролата и AI ключa са `hardcoded` в кода (виж [Препоръки](#препоръки-за-подобрения)).
- `send_app_mail()` използва PHP `mail()`, което е ненадеждно без proper MTA.
- Schema migrations вградени в `config.php` – трябва да са в отделен migration runner.

---

### `db/schema.sql` (319 реда)
**Роля:** Пълна reset + create схема.
- Деструктивна (`DROP TABLE IF EXISTS`) – подходяща за fresh install, не за production upgrade.
- Добре индексирана; Foreign Keys с `ON DELETE CASCADE`.
- `mb4_unicode_ci` charset навсякъде – правилно.

---

### `db/migrations/` (2 файла)
Съдържа SQL миграции за:
1. `001` – Добавя `owner_teacher_id` към `subjects`.
2. `002` – Създава `test_logs` таблицата.

**Проблем:** Нямат tracking таблица (напр. `schema_migrations`) – не се знае кои са приложени.

---

### `lib/helpers.php` (110 реда)
**Роля:** Чисти helper функции.
- `percent()`, `grade_from_percent()`, `get_grade_color_class()` – оценяване.
- `format_date()` – форматиране на дати.
- `require_role()` – auth guard за контролер ниво.
- `current_user()` – текущ потребител от сесията.
- `normalize_filter_datetime()` – нормализиране на datetime input.

**Добре написан** – кратък, документиран, без зависимости.

---

### `lib/AssignmentFactory.php`
**Роля:** Логика за publisher на задания.
- Изолирана класова логика (добра практика).

---

### `components/header.php` (213 реда)
**Роля:** Shared шапка – навигация + тема.
- Зарежда DB за класовете на ученика в profile dropdown (важна UX функционалност).
- Светла/Тъмна тема чрез `localStorage` + Bootstrap `data-bs-theme`.
- Driver.js tutorial само за ученици.

**Проблем:** Зарежда DB при всяко include на header – тясно свързване. Липсва кеширане.

---

### `index.php` (245 реда)
**Роля:** Начална страница.
- За учители: показва техните предмети и тестове.
- За гости/ученици: показва публично споделените.
- Търсачка, категории, скорошни тестове.

**Проблем:** Footer линковете (`terms.php`, `privacy.php`, `contact.php`) водят към несъществуващи файлове.

---

### `login.php` (153 реда)
**Роля:** Вход в системата.
- `password_verify()` – правилно.
- `sanitize_redirect_path()` за `next` параметъра – предпазва от open redirect.
- Универсално съобщение за грешка (не разкрива дали email е регистриран).
- Обновява `last_login_at`.

**Проблем:** Липсва rate limiting / lockout при множество неуспешни опити.

---

### `register.php` (164 реда)
**Роля:** Регистрация.
- Избор на роля (teacher/student) без никакво ограничение или код за потвърждение.
- `password_hash()` с `PASSWORD_DEFAULT` – правилно.
- Минимална парола: 8 символа.

**Проблеми:**
- Всеки може да се регистрира като учител – **липсва admin одобрение или invite код**.
- Регистрацията не вписва потребителя автоматично след успех (коментиран `header()` ред 49).
- Липсва email верификация.

---

### `dashboard.php` (593 реда)
**Роля:** Централен hub след вход.

**За учители:**
- Smart-engine "Now" stream – приоритизира задания с неоценени опити (🔥 Burning) пред активни.
- "Horizon" – библиотека с тестове и списък класове.
- Запазва dashboard филтри в сесия.

**За ученици:**
- Активни задания с маркировка за спешност (< 24ч до краен срок).
- История на опитите с оценки.
- Статистики от `v_student_overview` view.

**Проблем:** Файлът е 593 реда – твърде голям за single responsibility. Логиката за заявки трябва да се изнесе в service класове.

---

### `createTest.php` (998 реда)
**Роля:** Създаване и редактиране на тест.
- 3-стъпков wizard: Настройки → Въпроси → Публикуване.
- Vanilla JS state management за въпросите (без framework).
- AI генератор (Gemini) в модален прозорец.
- Excel импорт чрез `SimpleXLSX`.
- Типове въпроси: Single Choice, Multiple Choice, True/False, Free Text.
- Медийни прикачени файлове (изображения).

**Проблеми:**
- При редакция – **изтрива и преcъздава всички въпроси** ("Replace All" стратегия). Въпроси от question_bank могат да станат orphans.
- JS state management е fragile (ролбек на `syncState()` чрез DOM parsing).
- 998 реда в един файл – силно нарушен SRP.
- Коментари като `// TODO`, `// for brevity` оставени в production код.

---

### `test_view.php` (375 реда)
**Роля:** Показване и изпълнение на тест.
- `mode=preview` за учители и `mode=take` за ученици.
- Access control: проверява ownership/visibility и дали ученикът е в таргетирания клас.
- Submission: транзакция – INSERT attempt → INSERT attempt_answers → UPDATE attempt.
- Оценява Single, Multiple, True/False, Short Answer (case-insensitive), Numeric въпроси.

**Проблем:** Целият тест се показва наведнъж (без pagination по въпроси) – при голям брой въпроси UI е претоварен.

---

### `test_log_event.php`
**Роля:** API endpoint за античит логване.
- Записва действия: Tab hidden/visible, Fullscreen, Copy/Paste, Mouse leave, Context menu, etc.
- Действията са дефинирани в `test_log_allowed_actions()` в `config.php` – whitelist подход.

---

### `assignments.php` (192 реда)
**Роля:** Списък активни/архивни задания за учителя.
- Pagination (15 на страница).
- Търсене по заглавие.
- Бадж "🔥 за оценка" при неоценени опити.

---

### `assignments_create.php`
**Роля:** Форма за задание (test → клас(ове)/ученик(ци) + дата).
- Избор на тест, клас, дедлайн.
- Публикуване/запазване като чернова.

---

### `assignment_overview.php`
**Роля:** Мониторинг таблица – кой ученик как е отговорил.
- Показва score, % и оценка по ученик.
- Линк към ръчно оценяване.

---

### `attempt_review.php`
**Роля:** Ръчно оценяване на open-ended въпроси от учители.

---

### `grading_batch.php`
**Роля:** Групово оценяване на множество опити наведнъж.

---

### `student_attempt.php`
**Роля:** Детайлен резултат от опит за ученика.
- Показва score, оценка, правилни отговори (ако е разрешено от теста).

---

### `classes_create.php`
**Роля:** Управление на паралелки.
- Grade (клас) + Section (буква) + School year.
- Генерира уникален `join_token` (6 символа).
- Списък на записани ученици.

---

### `join_class.php`
**Роля:** Ученикът се присъединява с код или QR.

---

### `subjects_create.php`
**Роля:** Teacher-scoped управление на предмети (categories).
- Предметите са scoped по `owner_teacher_id` – всеки учител има своите.

---

### `api/generate_questions.php`
**Роля:** Gemini AI endpoint.
- Приема текст/тема, връща въпроси в JSON.
- API ключα е hardcoded в `config.php`.

---

### `assets/css/theme.css`
**Роля:** Глобални CSS custom properties и utility класове.
- Дефинира `--tg-primary`, `--tg-surface` и др.
- `glass-card`, `glass-header`, `hover-lift`, `animate-fade-up` анимации.
- Поддържа светла и тъмна тема.

---

### `assets/js/tutorial.js`
**Роля:** Driver.js тутorial за ученици.
- Step-by-step указания приърпвото влизане.

---

### Debug/Dev файлове (за изтриване/ограничаване)

| Файл | Проблем |
|---|---|
| `debug_ai.php` | Открит debug в production |
| `debug_schema.php` | Открит debug в production |
| `debug_output.txt` | 46KB debug лог – открит файл |
| `test_encoding.php` | Dev utility |
| `verify_schema.php` | Dev utility |

---

## Жизнен цикъл на тест

```
Учител                              Ученик
  │                                    │
  ├─ createTest.php                    │
  │    → question_bank + test_questions│
  │                                    │
  ├─ assignments_create.php            │
  │    → assignments                   │
  │    → assignment_classes           │
  │                                    │
  │                            join_class.php
  │                                    │ class_students
  │                                    │
  │                            dashboard.php
  │                              (open_assignments)
  │                                    │
  │                            test_view.php?mode=take
  │                              POST → attempts
  │                                    → attempt_answers
  │                                    → test_logs (антишийт)
  │                                    │
  ├─ grading_batch.php ←──────student_attempt.php
  │    (ръчно оценяване)               │ (преглед)
  │                                    │
  ├─ assignment_overview.php          my_attempts.php
       (статистики)
```

---

## Система за оценяване

| Оценка | Диапазон | CSS клас |
|--------|----------|----------|
| **6** (Отличен) | 90 – 100% | `success` (зелено) |
| **5** (Мн. добър) | 80 – 89% | `primary` (синьо) |
| **4** (Добър) | 65 – 79% | `info` (циан) |
| **3** (Среден) | 50 – 64% | `warning` (жълто) |
| **2** (Слаб) | 0 – 49% | `danger` (червено) |

Изчислява се от `grade_from_percent()` в `lib/helpers.php`. Учители могат да поставят и ръчна оценка (`teacher_grade`).

---

## Изисквания за стартиране

```
PHP     >= 7.4  (препоръчително 8.1+)
MySQL   >= 5.7  или MariaDB >= 10.3
Apache  (XAMPP) или Nginx с mod_rewrite
```

### Инсталация

1. Копирайте проекта в `htdocs/TestGramatikov/`
2. Създайте DB: `CREATE DATABASE gramtest_testgramatikov CHARACTER SET utf8mb4;`
3. Импортирайте схемата: `mysql -u gramtest -p gramtest_testgramatikov < db/schema.sql`
4. Настройте credentials в `config.php` (или env vars `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`)
5. Осигурете write права на `uploads/`
6. Отворете `http://localhost/TestGramatikov/`

---

## Препоръки за подобрения

### 🔴 КРИТИЧНО (Сигурност)

1. **Hardcoded credentials** – `config.php` ред 8 съдържа DB парола в plain text, ред 11 – AI API ключ.
   ```php
   // Сега (ЛОШО):
   define('DB_PASS', getenv('DB_PASS') ?: 'zbc2D!shaZirp7t');
   define('AI_API_KEY', getenv('AI_API_KEY') ?: 'AIzaSy...');
   
   // Трябва: използвайте .env файл (напр. vlucas/phpdotenv) или само env vars
   ```

2. **Отворени debug файлове** – `debug_ai.php`, `debug_schema.php`, `debug_output.txt` са публично достъпни. **Изтрийте ги** или добавете IP ограничение.

3. **Регистрация без ограничение** – всеки може да се регистрира като учител. Нужен е: invite код / admin одобрение / email верификация.

4. **Липса на Rate Limiting** – `login.php` не ограничава опитите за вход (brute-force уязвимост).

5. **CSRF защита** – формите не използват CSRF токени. При POST заявки (grade update, delete) трябва да се добавят.

6. **Медийни upload-и** – `uploads/` директорията трябва да е извън web root или да има `.htaccess` забраняващ изпълнението на PHP файлове.

---

### 🟠 ВАЖНО (Архитектура)

7. **Schema migrations без tracking** – `ensure_*()` функциите в `config.php` работят, но са неcтандартни. Преминете към инструмент като [Phinx](https://phinx.org/) или поне таблица `schema_migrations`.

8. **Монолитни файлове** – `config.php` (506 ред), `createTest.php` (998 ред), `dashboard.php` (593 ред) нарушават Single Responsibility Principle. Препоръка:
   ```
   lib/
     Auth.php          # session, require_role, login логика
     SchemaEnsure.php  # ensure_* функции
     TestService.php   # CRUD за тестове и въпроси
     AttemptService.php# submission, scoring
   ```

9. **Въпроси при редакция** – при всеки save на тест, всички въпроси се изтриват и преcъздават. Orphan записи в `question_bank` се натрупват. Нужна е стратегия за почистване или UPDATE in-place.

10. **DB заявки в header.php** – `components/header.php` прави DB заявка при всяко зареждане на страница. Използвайте `$_SESSION` кеш или PHP fragment caching.

---

### 🟡 ПРЕПОРЪЧИТЕЛНО (UX / Функционалност)

11. **Въпроси на отделни страници** – при тест с много въпроси, `test_view.php` показва всички наведнъж. Добавете навигация въпрос по въпрос (с таймер и прогрес бар).

12. **Email потвърждение** – регистрацията не изпраща verification email. `forgot_password.php` вече използва selector/token подход, приложете го и тук.

13. **Смесени езици** – в `login.php` ред 111 ("Your password was updated"), ред 126 ("Forgot your password?") текстовете са на английски. Преведете на bulgarian последователно.

14. **Несъществуващи pages** – `terms.php`, `privacy.php`, `contact.php` се рефeрeнцират в footer-а, но файловете не съществуват.

15. **Антишийт анализ** – `test_logs` събира богати данни (tab switch, copy/paste, mouse leave), но няма UI страница за анализа им. Добавете `test_log_review.php` за учителите.

16. **Bulk Excel import без preview** – при грешен Excel файл потребителят получава само съобщение за грешка, без да вижда какво е проблематично. Добавете preview стъпка.

17. **Въпросна банка** – въпросите са технически в `question_bank` таблица, но UI не позволява browsing и reuse на въпроси между различни тестове. Добавете `/question_bank.php`.

18. **Липсва Admin панел** – няма управление на потребители (suspend/delete), няма глобална статистика. Нужна е `admin/` директория с отделен достъп.

19. **Таймер при изпълнение на тест** – `time_limit_sec` полето съществува в DB, но `test_view.php` не имплементира countdown timer и принудително затваряне при изтичане на времето.

20. **Теми и theme_config** – `tests.theme` и `tests.theme_config` колоните са добавени, но `test_view.php` не ги прилага.

---

### 🟢 ОПТИМИЗАЦИИ

21. **N+1 заявки** – в `createTest.php` при зареждане на въпроси се прави отделна заявка за answers на всеки въпрос. Заменете с единна JOIN заявка.

22. **CDN Кеш** – `theme.css?v=<?= time() ?>` инвалидира кеша при всяко зареждане. Използвайте `filemtime()` вместо `time()`.

23. **Bootstrap Icons зареждан два пъти** – `index.php` редове 72-73 включват Bootstrap Icons двойно.

24. **Session regeneration след login** – `login.php` не извиква `session_regenerate_id(true)` след успешен вход (session fixation уязвимост).

25. **`.env` файл** – добавете `.env` + `.env.example` и включете `.env` в `.gitignore` за управление на secrets.

---

*Документацията е генерирана на базата на цялостен анализ на кода – Март 2026.*
