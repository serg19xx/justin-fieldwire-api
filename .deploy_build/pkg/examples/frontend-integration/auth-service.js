/**
 * Frontend Authentication Service
 * Пример интеграции с API аутентификации и аудита
 */

class AuthService {
    constructor() {
        this.baseUrl = '/api/v1/auth';
        this.checkSessionInterval = null;
        this.sessionTimeout = 30 * 60 * 1000; // 30 минут
        this.checkInterval = 5 * 60 * 1000; // Проверка каждые 5 минут
    }

    /**
     * Логин пользователя
     */
    async login(email, password) {
        try {
            const response = await fetch(`${this.baseUrl}/login`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ email, password })
            });

            const data = await response.json();

            if (response.ok && data.status === 'success') {
                // Сохраняем токен
                localStorage.setItem('auth_token', data.data.token);
                localStorage.setItem('user_data', JSON.stringify(data.data.user));
                
                // Запускаем проверку сессии
                this.startSessionCheck();
                
                return {
                    success: true,
                    user: data.data.user,
                    token: data.data.token,
                    requires2FA: data.data.requires_2fa || false
                };
            } else {
                return {
                    success: false,
                    error: data.message || 'Login failed'
                };
            }
        } catch (error) {
            console.error('Login error:', error);
            return {
                success: false,
                error: 'Network error'
            };
        }
    }

    /**
     * Логаут пользователя
     */
    async logout() {
        try {
            const token = this.getToken();
            if (token) {
                await fetch(`${this.baseUrl}/logout`, {
                    method: 'POST',
                    headers: {
                        'Authorization': `Bearer ${token}`,
                        'Content-Type': 'application/json'
                    }
                });
            }
        } catch (error) {
            console.error('Logout error:', error);
        } finally {
            // Очищаем локальные данные
            localStorage.removeItem('auth_token');
            localStorage.removeItem('user_data');
            this.stopSessionCheck();
            
            // Перенаправляем на страницу логина
            window.location.href = '/login';
        }
    }

    /**
     * Проверка валидности сессии
     */
    async checkSession() {
        try {
            const token = this.getToken();
            if (!token) {
                return false;
            }

            const response = await fetch(`${this.baseUrl}/check-session`, {
                method: 'POST',
                headers: {
                    'Authorization': `Bearer ${token}`,
                    'Content-Type': 'application/json'
                }
            });

            if (response.ok) {
                const data = await response.json();
                if (data.status === 'success') {
                    // Обновляем данные пользователя
                    localStorage.setItem('user_data', JSON.stringify(data.data.user));
                    return true;
                }
            }

            // Сессия недействительна
            this.logout();
            return false;

        } catch (error) {
            console.error('Session check error:', error);
            this.logout();
            return false;
        }
    }

    /**
     * Получить токен из localStorage
     */
    getToken() {
        return localStorage.getItem('auth_token');
    }

    /**
     * Получить данные пользователя
     */
    getUser() {
        const userData = localStorage.getItem('user_data');
        return userData ? JSON.parse(userData) : null;
    }

    /**
     * Проверить, авторизован ли пользователь
     */
    isAuthenticated() {
        return !!this.getToken();
    }

    /**
     * Запустить автоматическую проверку сессии
     */
    startSessionCheck() {
        this.stopSessionCheck(); // Останавливаем предыдущий интервал
        
        this.checkSessionInterval = setInterval(async () => {
            const isValid = await this.checkSession();
            if (!isValid) {
                console.log('Session expired, redirecting to login');
            }
        }, this.checkInterval);
    }

    /**
     * Остановить проверку сессии
     */
    stopSessionCheck() {
        if (this.checkSessionInterval) {
            clearInterval(this.checkSessionInterval);
            this.checkSessionInterval = null;
        }
    }

    /**
     * Инициализация при загрузке страницы
     */
    async init() {
        const token = this.getToken();
        if (token) {
            const isValid = await this.checkSession();
            if (isValid) {
                this.startSessionCheck();
                return true;
            }
        }
        return false;
    }

    /**
     * Создать заголовки для API запросов
     */
    getAuthHeaders() {
        const token = this.getToken();
        return {
            'Authorization': `Bearer ${token}`,
            'Content-Type': 'application/json'
        };
    }
}

// Пример использования
const authService = new AuthService();

// Инициализация при загрузке страницы
document.addEventListener('DOMContentLoaded', async () => {
    const isAuthenticated = await authService.init();
    
    if (!isAuthenticated) {
        // Пользователь не авторизован, показываем форму логина
        showLoginForm();
    } else {
        // Пользователь авторизован, показываем основное приложение
        showMainApp();
    }
});

// Обработка формы логина
async function handleLogin(event) {
    event.preventDefault();
    
    const email = document.getElementById('email').value;
    const password = document.getElementById('password').value;
    
    const result = await authService.login(email, password);
    
    if (result.success) {
        if (result.requires2FA) {
            show2FAForm();
        } else {
            showMainApp();
        }
    } else {
        showError(result.error);
    }
}

// Обработка логаута
async function handleLogout() {
    await authService.logout();
}

// Пример API запроса с авторизацией
async function makeAuthenticatedRequest(url, options = {}) {
    const headers = {
        ...authService.getAuthHeaders(),
        ...options.headers
    };

    try {
        const response = await fetch(url, {
            ...options,
            headers
        });

        if (response.status === 401) {
            // Токен истек, перенаправляем на логин
            authService.logout();
            return null;
        }

        return response;
    } catch (error) {
        console.error('API request error:', error);
        throw error;
    }
}

// Функции для управления UI (заглушки)
function showLoginForm() {
    console.log('Showing login form');
    // Здесь показать форму логина
}

function showMainApp() {
    console.log('Showing main app');
    // Здесь показать основное приложение
}

function show2FAForm() {
    console.log('Showing 2FA form');
    // Здесь показать форму 2FA
}

function showError(message) {
    console.error('Error:', message);
    // Здесь показать ошибку пользователю
}

// Экспорт для использования в других модулях
if (typeof module !== 'undefined' && module.exports) {
    module.exports = AuthService;
}
