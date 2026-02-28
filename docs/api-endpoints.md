# FieldWire API - Тестовые эндпоинты

## 🌐 Базовый URL
```
https://your-domain.com
```

## 📋 Доступные эндпоинты

### 1. Проверка здоровья API
```http
GET /api/v1/health
```

**Ответ:**
```json
{
  "status": "healthy",
  "timestamp": "2025-08-28T23:51:29+02:00",
  "uptime": {
    "seconds": 0,
    "formatted": "00:00:00"
  },
  "memory_usage": {
    "current": 4194304,
    "peak": 4194304,
    "limit": "512M"
  },
  "version": "1.0.0",
  "database": {
    "status": "connected"
  }
}
```

### 2. Информация о версии API
```http
GET /api/v1/version
```

**Ответ:**
```json
{
  "api_version": "v1",
  "status": "stable",
  "released": "2025-08-28",
  "endpoints": {
    "health": "/api/v1/health",
    "version": "/api/v1/version"
  }
}
```

### 3. Документация API
```http
GET /api
```

**Ответ:**
```json
{
  "name": "FieldWire API",
  "version": "1.0.0",
  "description": "REST API для FieldWire",
  "endpoints": {
    "health": "GET /api/v1/health",
    "version": "GET /api/v1/version",
    "database_tables": "GET /api/v1/database/tables"
  },
  "documentation": {
    "swagger_ui": "/api/docs",
    "openapi_spec": "/api/swagger/spec"
  }
}
```

### 4. Список таблиц базы данных
```http
GET /api/v1/database/tables
```

**Ответ:**
```json
{
  "status": "success",
  "tables": [
    "admin",
    "api_logs",
    "batch",
    "calendar",
    "car_locations_log",
    "central_pharm_contract",
    "central_prices_tmpl",
    "centralfill_order",
    "centralfill_quotes",
    "cities",
    "comp_centr_price",
    "comp_order_quote",
    "comp_patient_order",
    "compound_order",
    "compound_pharmacy_entry_table_1",
    "compound_prices_tmpl",
    "compound_quotes",
    "countries",
    "country",
    "delivery",
    "delivery_group",
    "driver",
    "driver_car",
    "drivers_invoice",
    "fw_users",
    "hollydays",
    "insurance_company",
    "knowledgebase",
    "medical_clinic",
    "patient",
    "patient_1",
    "patient_group",
    "patient_pharm_view",
    "pharma",
    "pharma_comp_sales_cycle",
    "pharma_driver_zone_price",
    "pharma_drivers",
    "pharma_drivers_view",
    "pharma_ext",
    "pharma_tmp",
    "pharma_zone",
    "pharmacist",
    "physician",
    "physician_sg_tmpl",
    "regions",
    "short_messages",
    "sms_text",
    "sms_text_pharm",
    "sms_text_queue",
    "sms_type",
    "subdivisions",
    "sys_params",
    "temp",
    "test",
    "tmp_csv_upload_table",
    "users"
  ],
  "count": 56,
  "database": "yjyhtqh8_easyrx",
  "timestamp": "2025-08-30T15:37:45+02:00"
}
```

### 5. Swagger UI
```http
GET /api/docs
```

### 6. OpenAPI спецификация
```http
GET /api/swagger/spec
```

### 7. Task Templates API (`/api/v1/task-templates`)

Управление шаблонами задач (таблица `fw_task_templates`). Используется на странице «Task Templates» и при создании задач «From templates».

| Метод | Путь | Описание | Роли |
|-------|------|----------|------|
| GET | `/api/v1/task-templates` | Список всех шаблонов | любой авторизованный |
| GET | `/api/v1/task-templates/:id` | Один шаблон по ID | любой авторизованный |
| POST | `/api/v1/task-templates` | Создать шаблон | admin, project_manager |
| PUT | `/api/v1/task-templates/:id` | Обновить шаблон | admin, project_manager |
| DELETE | `/api/v1/task-templates/:id` | Удалить шаблон | admin, project_manager |

**Формат ответов:** `status`, `message`, `data`. В `data` — `templates` (массив) или `template` (объект).

**Пример запроса (POST):**
```json
{
  "template": {
    "name": "Foundation Inspection",
    "description": "Inspection of completed foundation",
    "category": "Milestones",
    "duration_days": 1,
    "start_offset_days": 19,
    "milestone": "inspection",
    "status": "planned",
    "wbs_path": "1.2.1",
    "task_order": 13
  }
}
```

Подробная спецификация полей и кодов ответов — в описании задачи «Task Templates API — Backend Specification».

## 🧪 Тестирование с фронтенда

### JavaScript (Fetch API)
```javascript
// Проверка здоровья API
async function testHealth() {
    try {
        const response = await fetch('https://your-domain.com/api/v1/health');
        const data = await response.json();
        console.log('Health check:', data);
        return data;
    } catch (error) {
        console.error('Error:', error);
    }
}

// Информация о версии
async function testVersion() {
    try {
        const response = await fetch('https://your-domain.com/api/v1/version');
        const data = await response.json();
        console.log('Version info:', data);
        return data;
    } catch (error) {
        console.error('Error:', error);
    }
}

// Документация API
async function testApiDocs() {
    try {
        const response = await fetch('https://your-domain.com/api');
        const data = await response.json();
        console.log('API docs:', data);
        return data;
    } catch (error) {
        console.error('Error:', error);
    }
}

// Список таблиц базы данных
async function testDatabaseTables() {
    try {
        const response = await fetch('https://your-domain.com/api/v1/database/tables');
        const data = await response.json();
        console.log('Database tables:', data);
        return data;
    } catch (error) {
        console.error('Error:', error);
    }
}
```

### JavaScript (Axios)
```javascript
import axios from 'axios';

const API_BASE_URL = 'https://your-domain.com';

// Проверка здоровья API
const testHealth = async () => {
    try {
        const response = await axios.get(`${API_BASE_URL}/api/v1/health`);
        console.log('Health check:', response.data);
        return response.data;
    } catch (error) {
        console.error('Error:', error.response?.data || error.message);
    }
};

// Информация о версии
const testVersion = async () => {
    try {
        const response = await axios.get(`${API_BASE_URL}/api/v1/version`);
        console.log('Version info:', response.data);
        return response.data;
    } catch (error) {
        console.error('Error:', error.response?.data || error.message);
    }
};

// Список таблиц базы данных
const testDatabaseTables = async () => {
    try {
        const response = await axios.get(`${API_BASE_URL}/api/v1/database/tables`);
        console.log('Database tables:', response.data);
        return response.data;
    } catch (error) {
        console.error('Error:', error.response?.data || error.message);
    }
};
```

### cURL команды
```bash
# Проверка здоровья
curl -X GET https://your-domain.com/api/v1/health

# Информация о версии
curl -X GET https://your-domain.com/api/v1/version

# Документация API
curl -X GET https://your-domain.com/api

# Список таблиц базы данных
curl -X GET https://your-domain.com/api/v1/database/tables

# Swagger UI
curl -X GET https://your-domain.com/api/docs
```

## 🔧 Настройка CORS

API настроен для работы с фронтендом. CORS заголовки автоматически добавляются для:
- Разрешенных доменов (настраивается в .env)
- В режиме разработки разрешены все домены (*)

## 📊 Мониторинг

Все запросы логируются в таблицу `api_logs` (если база данных настроена):
- HTTP метод
- Путь запроса
- Статус код
- Время ответа
- User-Agent
- IP адрес
- Временная метка

