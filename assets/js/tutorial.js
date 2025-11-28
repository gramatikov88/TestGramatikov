document.addEventListener('DOMContentLoaded', function () {
    // Check if driver.js is loaded
    if (!window.driver || !window.driver.js) {
        console.warn('Driver.js not loaded');
        return;
    }

    const driver = window.driver.js.driver;
    // We need to get the user role. Since this is a JS file, we can't use PHP directly.
    // We'll assume the role is set in a global variable or data attribute in the body/header.
    // For now, let's try to find it from the body data attribute if we add one, 
    // or fallback to checking for specific elements that only certain roles have.
    // A robust way is to add <body data-role="<?= $user['role'] ?>"> in header.php
    // But for now, let's try to infer or use a global variable if defined.

    // Let's look for a global variable defined in header or dashboard.
    // If not, we might need to add it to header.php.
    // For this iteration, I'll assume we'll add `window.currentUserRole` in header.php

    const userRole = window.currentUserRole || 'student'; // Default fallback
    const toggle = document.getElementById('navHelpToggle');

    if (!toggle) return;

    // Define steps for each page
    const stepsConfig = {
        'dashboard.php': {
            'teacher': [
                { popover: { title: 'Добре дошли!', description: 'Това е вашето табло за управление. Тук можете да управлявате класове, тестове и задания.' } },
                { element: '.hero-actions', popover: { title: 'Бързи действия', description: 'От тук можете бързо да създавате нови тестове, класове и задания.', side: 'bottom' } },
                { element: '.col-lg-5 .row', popover: { title: 'Статистика', description: 'Бърз преглед на вашите класове, тестове и активност.', side: 'left' } },
                { element: '.filter-card', popover: { title: 'Филтри', description: 'Използвайте тези филтри, за да намерите конкретни класове, тестове или задания.', side: 'top' } },
                { element: '[data-card-key="teacher-classes"]', popover: { title: 'Вашите класове', description: 'Списък с всички ваши класове. Можете да ги редактирате или да добавяте ученици.', side: 'top' } },
                { element: '[data-card-key="teacher-tests"]', popover: { title: 'Вашите тестове', description: 'Всички създадени от вас тестове. Можете да ги редактирате, споделяте или изтривате.', side: 'top' } },
                { element: '[data-card-key="teacher-recent-attempts"]', popover: { title: 'Последни опити', description: 'Тук ще видите последните предадени тестове от ученици. Можете да ги оценявате директно.', side: 'top' } },
                { element: '[data-card-key="teacher-assignments-current"]', popover: { title: 'Активни задания', description: 'Списък с текущите задания, които сте възложили.', side: 'top' } }
            ],
            'student': [
                { popover: { title: 'Добре дошли!', description: 'Това е вашето табло. Тук ще намерите вашите задания и резултати.' } },
                { element: '.join-code-entry', popover: { title: 'Влез в клас', description: 'Въведете кода, предоставен от вашия учител, за да се присъедините към клас.', side: 'right' } },
                { element: '.hero-actions', popover: { title: 'Бързи връзки', description: 'Бърз достъп до вашите активни задания и тестове.', side: 'bottom' } },
                { element: '#student-assignments', popover: { title: 'Вашите задания', description: 'Тук са всички тестове, които трябва да направите. Следете сроковете!', side: 'top' } }
            ]
        },
        'classes_create.php': {
            'teacher': [
                { popover: { title: 'Управление на клас', description: 'Тук създавате или редактирате информацията за класа.' } },
                { element: '#classForm', popover: { title: 'Данни за класа', description: 'Въведете име, клас, паралелка и учебна година. Тези полета са задължителни.', side: 'bottom' } },
                { element: '#share', popover: { title: 'Покана на ученици', description: 'Използвайте този QR код или линк, за да поканите ученици в класа.', side: 'top' } },
                { element: '#studentsCard', popover: { title: 'Списък с ученици', description: 'Тук ще видите всички ученици, които са се присъединили към класа. Можете да ги премахвате при нужда.', side: 'top' } },
                { element: 'button[type="submit"]', popover: { title: 'Запазване', description: 'Не забравяйте да запазите промените си!', side: 'top' } }
            ]
        },
        'createTest.php': {
            'teacher': [
                { popover: { title: 'Създаване на тест', description: 'Тук можете да създадете нов тест с различни видове въпроси.' } },
                { element: '#testForm .card-body:first-of-type', popover: { title: 'Основни настройки', description: 'Задайте заглавие, описание, времеви лимит и други настройки за теста.', side: 'bottom' } },
                { element: '#questions', popover: { title: 'Въпроси', description: 'Тук добавяте и редактирате въпросите. Можете да избирате между различни типове: единичен избор, множествен избор, вярно/грешно и попълване.', side: 'top' } },
                { element: '#addQuestionBtn', popover: { title: 'Нов въпрос', description: 'Натиснете тук, за да добавите нов въпрос към теста.', side: 'top' } },
                { element: 'button[name="save_test"]', popover: { title: 'Запазване', description: 'Когато сте готови, запазете теста. Можете да го оставите като "Чернова", докато не сте готови да го публикувате.', side: 'top' } }
            ]
        },
        'test_edit.php': { // Alias for createTest.php logic if needed, but usually same file
            'teacher': [
                { popover: { title: 'Редакция на тест', description: 'Можете да променяте въпросите и настройките на теста по всяко време.' } },
                { element: '#questions', popover: { title: 'Въпроси', description: 'Редактирайте съдържанието на въпросите или добавете нови.', side: 'top' } },
                { element: '#addQuestionBtn', popover: { title: 'Нов въпрос', description: 'Добавете още въпроси към теста.', side: 'top' } }
            ]
        },
        'join_class.php': {
            'student': [
                { popover: { title: 'Присъединяване към клас', description: 'Прегледайте информацията за класа преди да се присъедините.' } },
                { element: '.card-body', popover: { title: 'Информация', description: 'Уверете се, че това е правилният клас и учител.', side: 'bottom' } },
                { element: 'button[type="submit"]', popover: { title: 'Потвърждение', description: 'Натиснете бутона, за да се включите в класа.', side: 'top' } }
            ]
        },
        'attempt_review.php': {
            'teacher': [
                { popover: { title: 'Преглед на опит', description: 'Тук виждате как се е представил ученикът.' } },
                { element: '.card-body:first-of-type', popover: { title: 'Резултат', description: 'Общ брой точки и автоматична оценка.', side: 'bottom' } },
                { element: 'form', popover: { title: 'Вашата оценка', description: 'Можете да промените или потвърдите оценката ръчно.', side: 'left' } },
                { element: '.q-card', popover: { title: 'Отговори', description: 'Прегледайте всеки отговор детайлно.', side: 'top' } }
            ],
            'student': [
                { popover: { title: 'Преглед на резултат', description: 'Вижте как сте се справили с теста.' } },
                { element: '.card-body:first-of-type', popover: { title: 'Вашият резултат', description: 'Тук виждате точките и оценката си.', side: 'bottom' } },
                { element: '.q-card', popover: { title: 'Грешки и верни отговори', description: 'Прегледайте къде сте сгрешили и кои са верните отговори.', side: 'top' } }
            ]
        }
    };

    // Determine current page
    const path = window.location.pathname;
    const page = path.split('/').pop();

    // Get steps for current page and role
    // Handle aliases or query params if necessary (e.g. createTest.php vs test_edit.php if they are different files, but here createTest handles both)
    let currentSteps = stepsConfig[page] ? stepsConfig[page][userRole] : [];

    // Fallback/Cleanup for empty steps
    if (!currentSteps || currentSteps.length === 0) {
        // If no steps for this page, hide the toggle or disable it?
        // For now, we just won't start the driver.
        // Optionally, we could disable the toggle visually.
        // toggle.disabled = true;
        // return;
    }

    const driverObj = driver({
        showProgress: true,
        steps: currentSteps || [],
        onDestroyed: () => {
            toggle.checked = false;
            // Optionally save state as 'seen' or 'off'
            localStorage.setItem('tg_tutorial_enabled', 'false');
        }
    });

    // Handle Toggle
    toggle.addEventListener('change', (e) => {
        if (e.target.checked) {
            if (currentSteps && currentSteps.length > 0) {
                driverObj.drive();
                localStorage.setItem('tg_tutorial_enabled', 'true');
            } else {
                // No tutorial for this page
                alert('За тази страница няма наличен туториал.');
                e.target.checked = false;
            }
        } else {
            driverObj.destroy();
            localStorage.setItem('tg_tutorial_enabled', 'false');
        }
    });

    // Auto-start if enabled in localStorage
    // And only if we have steps
    const isEnabled = localStorage.getItem('tg_tutorial_enabled') === 'true';
    // Also check if it's the first visit (no localStorage item) -> Default ON
    const isFirstVisit = localStorage.getItem('tg_tutorial_enabled') === null;

    if ((isEnabled || isFirstVisit) && currentSteps && currentSteps.length > 0) {
        toggle.checked = true;
        // Small delay to ensure UI is ready
        setTimeout(() => {
            driverObj.drive();
        }, 1000);
    } else {
        toggle.checked = false;
    }
});
