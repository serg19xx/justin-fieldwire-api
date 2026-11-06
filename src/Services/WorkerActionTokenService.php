<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\ApiUrl;
use Doctrine\DBAL\Connection;

/**
 * One-tap links in worker SMS ("Still working?", "Arrived at site"). The link opens a confirmation
 * page; only its form POST changes data, because SMS apps prefetch links. Only the hash is stored.
 */
class WorkerActionTokenService
{
    public const ACTION_EXTEND = 'extend';
    public const ACTION_ARRIVE = 'arrive';

    private const TABLE = 'fw_worker_action_tokens';

    /** Links stay valid until this hour of the next morning (late shifts). */
    private const EXPIRES_NEXT_DAY_HOUR = 6;

    private static ?bool $tableExists = null;

    private WorkClockService $clock;

    public function __construct(?WorkClockService $clock = null)
    {
        $this->clock = $clock ?? new WorkClockService();
    }

    public function tableExists(Connection $conn): bool
    {
        return self::$tableExists ??= (bool) $conn->executeQuery(
            'SHOW TABLES LIKE ' . $conn->quote(self::TABLE)
        )->fetchOne();
    }

    public function create(Connection $conn, int $userId, string $workDateYmd, string $action, ?int $projectId = null): string
    {
        $token = bin2hex(random_bytes(16));
        $expires = (new \DateTimeImmutable($workDateYmd, $this->clock->timezone()))
            ->modify('+1 day')
            ->setTime(self::EXPIRES_NEXT_DAY_HOUR, 0);
        $conn->insert(self::TABLE, [
            'token_hash' => hash('sha256', $token),
            'user_id' => $userId,
            'work_date' => $workDateYmd,
            'action' => $action,
            'project_id' => $projectId,
            'expires_at' => $this->clock->nowSql($expires),
        ]);

        return $token;
    }

    public static function url(string $token): string
    {
        return ApiUrl::base() . '/api/v1/worker-actions/' . $token;
    }

    /**
     * @return array{id: int, user_id: int, work_date: string, action: string, project_id: ?int}|null
     */
    public function find(Connection $conn, string $token): ?array
    {
        if (preg_match('/^[a-f0-9]{32}$/', $token) !== 1 || !$this->tableExists($conn)) {
            return null;
        }
        $row = $conn->executeQuery(
            'SELECT id, user_id, work_date, action, project_id FROM ' . self::TABLE . '
             WHERE token_hash = ? AND expires_at > ?',
            [hash('sha256', $token), $this->clock->nowSql()]
        )->fetchAssociative();
        if (!$row) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'user_id' => (int) $row['user_id'],
            'work_date' => substr((string) $row['work_date'], 0, 10),
            'action' => (string) $row['action'],
            'project_id' => $row['project_id'] !== null ? (int) $row['project_id'] : null,
        ];
    }

    public function markUsed(Connection $conn, int $id): void
    {
        $conn->update(self::TABLE, ['last_used_at' => $this->clock->nowSql()], ['id' => $id]);
    }
}
