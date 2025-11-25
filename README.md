# TestGramatikov Platform

TestGramatikov is a web-based platform for creating, managing, and taking educational tests. It serves two main roles: **Teachers** and **Students**.

## Project Structure & Functionality

### Core System
- **`index.php`**: The landing page. Displays featured categories, recent tests, and general information about the platform.
- **`config.php`**: Database configuration and helper functions (DB connection, session management).
- **`dashboard.php`**: The central hub for logged-in users.
    - **Teachers**: View classes, recent test attempts, assignments needing grading, and statistics.
    - **Students**: View active assignments, recent results, and class information.
- **`login.php` / `register.php`**: User authentication and registration.
- **`forgot_password.php` / `reset_password.php`**: Password recovery flow.
- **`logout.php`**: Ends the user session.

### Test Management (Teachers)
- **`tests.php`**: A searchable catalog of tests. Teachers can filter by subject, visibility, and status.
- **`createTest.php`**: Interface for creating and editing tests. Supports:
    - Manual question entry (Single Choice, Multiple Choice, True/False, Fill in the blank).
    - Excel import for bulk question creation.
    - Media attachments (images) for questions.
    - Settings for time limits, attempt limits, and randomization.
- **`test_edit.php`**: (Redirects or handles specific edit actions, often linked with `createTest.php`).
- **`test_view.php`**:
    - **Preview Mode**: Allows teachers to see how the test looks.
    - **Take Mode**: The interface where students actually take the test.

### Assignments & Grading
- **`assignment.php`**: Manages specific assignment details.
- **`assignments_create.php`**: Allows teachers to assign a test to specific classes or students with deadlines.
- **`student_attempt.php`**: Displays the results of a specific test attempt. Shows score, calculated grade (2-6 scale), and correct answers (if allowed by settings).
- **`attempt_review.php`**: Allows teachers to review student attempts and manually grade open-ended questions.
- **`my_attempts.php`**: A history of all attempts for a student.

### Class & Subject Management
- **`classes_create.php`**: Teachers can create and manage classes (Grade + Section).
- **`join_class.php`**: Students can join classes using a code or link.
- **`subjects_create.php`**: Management of subjects (categories) for tests.
- **`categories.php`**: Public view of test categories.
- **`students_search.php`**: Helper for finding students (likely for assignments).

### Components & Assets
- **`components/header.php`**: Navigation bar and theme switcher (Light/Dark mode).
- **`assets/css/theme.css`**: Global styles and theme variables.
- **`lib/`**: External libraries (e.g., `SimpleXLSX.php` for Excel import).
- **`uploads/`**: Directory for storing question media files.

## Grading System
The platform uses a standard 6-point grading scale based on percentage:
- **6 (Excellent)**: 90% - 100%
- **5 (Very Good)**: 80% - 89%
- **4 (Good)**: 65% - 79%
- **3 (Fair)**: 50% - 64%
- **2 (Poor)**: 0% - 49%

## Requirements
- PHP 7.4+
- MySQL/MariaDB
- Apache/Nginx
