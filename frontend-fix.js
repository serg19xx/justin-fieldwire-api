// ПРАВИЛЬНАЯ фронтенд логика проверки границ проекта для календаря
// Этот код нужно заменить в ProjectCalendar.vue или project-bounds-checker.ts

function checkProjectBounds(taskStart, taskEnd, projectStart, projectEnd) {
    console.log('🔍 checkProjectBounds called with:', {
        taskStart,
        taskEnd,
        projectStart,
        projectEnd
    });

    // Преобразуем строки в объекты Date
    const taskStartDate = new Date(taskStart);
    const taskEndDate = new Date(taskEnd);
    const projectStartDate = new Date(projectStart);
    const projectEndDate = new Date(projectEnd);

    // Сбрасываем время на 00:00:00 для корректного сравнения дат
    taskStartDate.setHours(0, 0, 0, 0);
    taskEndDate.setHours(0, 0, 0, 0);
    projectStartDate.setHours(0, 0, 0, 0);
    projectEndDate.setHours(0, 0, 0, 0);

    console.log('📅 Dates after normalization:', {
        taskStartDate: taskStartDate.toISOString().split('T')[0],
        taskEndDate: taskEndDate.toISOString().split('T')[0],
        projectStartDate: projectStartDate.toISOString().split('T')[0],
        projectEndDate: projectEndDate.toISOString().split('T')[0]
    });

    // ПРАВИЛЬНАЯ логика проверки границ:
    // 1. Задача может начинаться в день начала проекта или позже
    // 2. Задача может заканчиваться в день окончания проекта или раньше
    const startValid = taskStartDate >= projectStartDate;
    const endValid = taskEndDate <= projectEndDate; // <= означает НЕ больше

    const isWithinBounds = startValid && endValid;

    console.log('🔍 Bounds check result:', {
        startValid,
        endValid,
        isWithinBounds,
        taskStartStr: taskStartDate.toISOString().split('T')[0],
        taskEndStr: taskEndDate.toISOString().split('T')[0],
        projectStartStr: projectStartDate.toISOString().split('T')[0],
        projectEndStr: projectEndDate.toISOString().split('T')[0]
    });

    return {
        isWithinBounds,
        startValid,
        endValid,
        reason: !startValid ? 'Task starts before project starts' : 
                !endValid ? 'Task ends after project ends' : null
    };
}

// Обработчик перетаскивания задачи в календаре
function handleTaskResize(event, projectInfo) {
    const taskStart = event.start.toISOString().split('T')[0];
    const taskEnd = event.end.toISOString().split('T')[0];
    
    console.log('📅 Task resized:', {
        taskStart,
        taskEnd,
        projectStart: projectInfo.date_start,
        projectEnd: projectInfo.date_end
    });
    
    // Проверяем границы проекта
    const boundsCheck = checkProjectBounds(taskStart, taskEnd, projectInfo.date_start, projectInfo.date_end);
    
    if (!boundsCheck.isWithinBounds) {
        // Показываем диалог только если задача выходит за границы
        console.log('⚠️ Task is outside project bounds:', boundsCheck.reason);
        showProjectBoundsDialog(boundsCheck, event);
    } else {
        console.log('✅ Task is within project bounds - sending to backend');
        // Отправляем данные на бэкенд только если валидация прошла успешно
        updateTaskOnBackend(event);
    }
}

// Функция для показа диалога (если задача выходит за границы)
function showProjectBoundsDialog(boundsCheck, event) {
    // Ваша логика показа диалога
    console.log('🚨 Showing bounds dialog:', boundsCheck);
    
    // Пример диалога:
    const message = boundsCheck.reason === 'Task starts before project starts' 
        ? 'Task start date cannot be before project start date'
        : 'Task end date cannot be after project end date';
    
    // Показать диалог с кнопками "Cancel" и "Adjust"
    // Если пользователь выбирает "Adjust", скорректировать даты
    // Если "Cancel", вернуть задачу в исходное положение
}

// Функция для отправки данных на бэкенд (только если валидация прошла)
function updateTaskOnBackend(event) {
    console.log('💾 Sending task update to backend:', event);
    
    // Ваша логика отправки на бэкенд
    // fetch('/api/v1/projects/.../tasks/...', {
    //     method: 'PUT',
    //     body: JSON.stringify({
    //         start_planned: event.start.toISOString().split('T')[0],
    //         end_planned: event.end.toISOString().split('T')[0]
    //     })
    // });
}

// Примеры тестирования:
console.log('=== Тесты проверки границ ===');

// Тест 1: Задача заканчивается в последний день проекта (8 октября) - ДОЛЖНО БЫТЬ ВАЛИДНО
const test1 = checkProjectBounds('2025-10-06', '2025-10-08', '2025-09-04', '2025-10-08');
console.log('Тест 1 (8 октября):', test1.isWithinBounds ? '✅ ВАЛИДНО' : '❌ НЕ ВАЛИДНО');

// Тест 2: Задача заканчивается после проекта (9 октября) - ДОЛЖНО БЫТЬ НЕ ВАЛИДНО
const test2 = checkProjectBounds('2025-10-06', '2025-10-09', '2025-09-04', '2025-10-08');
console.log('Тест 2 (9 октября):', test2.isWithinBounds ? '✅ ВАЛИДНО' : '❌ НЕ ВАЛИДНО');

// Тест 3: Задача заканчивается до последнего дня проекта (7 октября) - ДОЛЖНО БЫТЬ ВАЛИДНО
const test3 = checkProjectBounds('2025-10-06', '2025-10-07', '2025-09-04', '2025-10-08');
console.log('Тест 3 (7 октября):', test3.isWithinBounds ? '✅ ВАЛИДНО' : '❌ НЕ ВАЛИДНО');
