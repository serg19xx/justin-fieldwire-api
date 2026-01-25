# Миграция: Добавление новых полей в таблицу fw_projects

## Описание

Этот скрипт добавляет новые поля в таблицу `fw_projects` для поддержки:
- `purchase_or_lease` - тип покупки/аренды
- `notes` - заметки по проекту
- `client_id`, `client_type`, `client_table`, `client_data` - информация о клиенте

## Проблема: 500 Error при запросе GET /api/v1/projects

Если вы получаете ошибку 500 при запросе проектов, скорее всего в таблице отсутствуют новые поля.

### Симптомы:
- `GET /api/v1/projects` возвращает 500 Internal Server Error
- В логах ошибка типа "Unknown column 'purchase_or_lease' in 'field list'"

## Решение

### Шаг 1: Проверьте версию MySQL/MariaDB

```sql
SELECT VERSION();
```

- **MySQL 5.7+ или MariaDB 10.2+**: Используйте `add-project-client-fields.sql`
- **MySQL < 5.7 или MariaDB < 10.2**: Используйте `add-project-client-fields-mysql-old.sql`

### Шаг 2: Проверьте существование полей

Вы можете использовать скрипт проверки:

```bash
mysql -u your_user -p your_database < scripts/check-project-fields.sql
```

Или вручную:

```sql
DESCRIBE fw_projects;
```

Или:

```sql
SHOW COLUMNS FROM fw_projects LIKE 'purchase_or_lease';
SHOW COLUMNS FROM fw_projects LIKE 'notes';
SHOW COLUMNS FROM fw_projects LIKE 'client_id';
```

### Шаг 3: Выполните миграцию

#### Для MySQL 5.7+ / MariaDB 10.2+:

```bash
mysql -u your_user -p your_database < scripts/add-project-client-fields.sql
```

Или через MySQL клиент:

```sql
SOURCE scripts/add-project-client-fields.sql;
```

#### Для старых версий MySQL:

```bash
mysql -u your_user -p your_database < scripts/add-project-client-fields-mysql-old.sql
```

### Шаг 4: Проверьте результат

```sql
DESCRIBE fw_projects;
```

Должны появиться новые поля:
- `purchase_or_lease`
- `notes`
- `client_id`
- `client_type`
- `client_table`
- `client_data`

## Откат миграции (если нужно)

```sql
ALTER TABLE `fw_projects` 
DROP COLUMN `client_data`,
DROP COLUMN `client_table`,
DROP COLUMN `client_type`,
DROP COLUMN `client_id`,
DROP COLUMN `notes`,
DROP COLUMN `purchase_or_lease`;

DROP INDEX `idx_purchase_or_lease` ON `fw_projects`;
DROP INDEX `idx_client_id` ON `fw_projects`;
DROP INDEX `idx_client_table` ON `fw_projects`;
```

## Проверка после миграции

После выполнения миграции проверьте API:

```bash
curl -X GET "http://your-api/api/v1/projects?page=1&limit=100" \
  -H "Authorization: Bearer YOUR_TOKEN"
```

Должен вернуться успешный ответ со списком проектов, включая новые поля.

## Примечания

- Миграция безопасна для существующих данных - все новые поля имеют значения по умолчанию или NULL
- Индексы добавлены для улучшения производительности запросов
- Для `client_data` используется JSON тип (или TEXT для старых версий MySQL)
