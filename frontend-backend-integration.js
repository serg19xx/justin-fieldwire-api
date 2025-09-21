// Интеграция фронтенда с бэкенд валидацией
// Этот код нужно добавить в ProjectCalendar.vue или project-bounds-checker.ts

async function checkProjectBoundsWithBackend(projectId, taskStart, taskEnd) {
    try {
        console.log('🔍 Checking bounds with backend API...');
        
        // Вызываем бэкенд API для проверки границ
        const response = await fetch(`/api/v1/projects/${projectId}/tasks/check-bounds?start_planned=${taskStart}&end_planned=${taskEnd}`, {
            method: 'GET',
            headers: {
                'Authorization': `Bearer ${getAuthToken()}`, // функция для получения токена
                'Content-Type': 'application/json'
            }
        });
        
        const result = await response.json();
        
        console.log('📡 Backend response:', result);
        
        if (result.error_code === 0) {
            // Бэкенд валидация прошла успешно
            return {
                isValid: result.data.valid,
                message: result.data.message,
                projectStart: result.data.project_start,
                projectEnd: result.data.project_end
            };
        } else {
            // Ошибка API
            console.error('❌ Backend validation error:', result.message);
            return {
                isValid: false,
                message: result.message || 'Backend validation failed'
            };
        }
        
    } catch (error) {
        console.error('❌ Failed to check bounds with backend:', error);
        // Fallback на фронтенд валидацию в случае ошибки
        return checkProjectBoundsFrontend(taskStart, taskEnd, projectStart, projectEnd);
    }
}

// Fallback функция для фронтенд валидации (если бэкенд недоступен)
function checkProjectBoundsFrontend(taskStart, taskEnd, projectStart, projectEnd) {
    console.log('🔄 Using frontend validation as fallback...');
    
    const taskStartDate = new Date(taskStart);
    const taskEndDate = new Date(taskEnd);
    const projectStartDate = new Date(projectStart);
    const projectEndDate = new Date(projectEnd);
    
    // Нормализуем время
    taskStartDate.setHours(0, 0, 0, 0);
    taskEndDate.setHours(0, 0, 0, 0);
    projectStartDate.setHours(0, 0, 0, 0);
    projectEndDate.setHours(0, 0, 0, 0);
    
    const startValid = taskStartDate >= projectStartDate;
    const endValid = taskEndDate <= projectEndDate;
    
    return {
        isValid: startValid && endValid,
        message: !startValid ? 'Task starts before project starts' : 
                !endValid ? 'Task ends after project ends' : null
    };
}

// Функция для получения токена авторизации
function getAuthToken() {
    // Замените на вашу логику получения токена
    return localStorage.getItem('auth_token') || sessionStorage.getItem('auth_token');
}

// Пример использования в обработчике перетаскивания задачи:
async function handleTaskResize(event, projectId, projectInfo) {
    const taskStart = event.start.toISOString().split('T')[0];
    const taskEnd = event.end.toISOString().split('T')[0];
    
    console.log('📅 Task resized:', {
        taskStart,
        taskEnd,
        projectStart: projectInfo.date_start,
        projectEnd: projectInfo.date_end
    });
    
    // Проверяем границы через бэкенд API
    const boundsCheck = await checkProjectBoundsWithBackend(projectId, taskStart, taskEnd);
    
    if (!boundsCheck.isValid) {
        // Показываем диалог только если бэкенд говорит, что задача выходит за границы
        console.log('⚠️ Task is outside project bounds:', boundsCheck.message);
        showProjectBoundsDialog(boundsCheck);
    } else {
        console.log('✅ Task is within project bounds');
        // Продолжаем с обновлением задачи
        updateTaskOnServer(event);
    }
}

// Функция для показа диалога
function showProjectBoundsDialog(boundsCheck) {
    // Ваша логика показа диалога
    console.log('🚨 Showing bounds dialog:', boundsCheck);
}

// Функция для обновления задачи на сервере
function updateTaskOnServer(event) {
    // Ваша логика обновления задачи
    console.log('💾 Updating task on server:', event);
}
