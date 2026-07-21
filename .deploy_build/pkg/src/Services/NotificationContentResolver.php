<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Database;
use Doctrine\DBAL\Connection;
use Monolog\Logger;
use Throwable;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

/**
 * Resolves notify content per channel: system | local (Message Templates) | manual.
 * Local templates are rendered with Twig; SendGrid/Twilio are transport only.
 */
class NotificationContentResolver
{
    private Connection $connection;

    public function __construct(
        private readonly Logger $logger,
    ) {
        $this->connection = Database::getConnection();
    }

    /**
     * @param array<string, mixed> $payload Outbox event payload
     * @param array<string, mixed> $action Normalized notify action (may include channel_content / channel_templates)
     * @return array{
     *   title: string,
     *   message: string,
     *   email_subject: string,
     *   email_html: string,
     *   sms_body: string,
     *   push_title: string,
     *   push_body: string
     * }
     */
    public function resolve(array $payload, array $action): array
    {
        $vars = $this->buildVariables($payload);
        $systemTitle = $this->systemTitle($payload, $vars);
        $systemMessage = $this->systemMessage($payload, $vars, $systemTitle);

        $email = $this->resolveChannel('email', $action, $payload, $vars, $systemTitle, $systemMessage);
        $sms = $this->resolveChannel('sms', $action, $payload, $vars, $systemTitle, $systemMessage);
        $push = $this->resolveChannel('push', $action, $payload, $vars, $systemTitle, $systemMessage);

        return [
            'title' => $systemTitle,
            'message' => $systemMessage,
            'email_subject' => $email['subject'] !== '' ? $email['subject'] : $systemTitle,
            'email_html' => $email['body'] !== '' ? $email['body'] : $systemMessage,
            'sms_body' => $sms['body'] !== '' ? $sms['body'] : $systemTitle,
            'push_title' => $push['subject'] !== '' ? $push['subject'] : $systemTitle,
            'push_body' => $push['body'] !== '' ? mb_substr($push['body'], 0, 180) : mb_substr($systemMessage, 0, 180),
        ];
    }

    /**
     * Normalize legacy channel_templates into channel_content on the action array.
     *
     * @param array<string, mixed> $action
     * @return array<string, mixed>
     */
    public function normalizeActionContent(array $action): array
    {
        $content = [];
        if (isset($action['channel_content']) && is_array($action['channel_content'])) {
            $content = $action['channel_content'];
        }

        $legacy = $action['channel_templates'] ?? null;
        if (is_array($legacy)) {
            foreach (['email', 'sms', 'push'] as $channel) {
                if (isset($content[$channel]) && is_array($content[$channel])) {
                    continue;
                }
                $tid = $legacy[$channel] ?? null;
                if ($tid !== null && $tid !== '' && is_numeric($tid)) {
                    $content[$channel] = [
                        'mode' => 'local',
                        'template_id' => (int) $tid,
                    ];
                }
            }
        }

        $action['channel_content'] = $content;
        return $action;
    }

    /**
     * @param array<string, mixed> $action
     * @param array<string, mixed> $payload
     * @param array<string, string> $vars
     * @return array{subject: string, body: string}
     */
    private function resolveChannel(
        string $channel,
        array $action,
        array $payload,
        array $vars,
        string $systemTitle,
        string $systemMessage
    ): array {
        $spec = $this->channelSpec($action, $channel);
        $mode = strtolower((string) ($spec['mode'] ?? 'system'));

        if ($mode === 'local') {
            $templateId = isset($spec['template_id']) ? (int) $spec['template_id'] : 0;
            if ($templateId > 0) {
                $rendered = $this->renderLocalTemplate($templateId, $channel, $vars);
                if ($rendered !== null) {
                    return $rendered;
                }
            }
            $this->logger->warning('Local template missing or failed; falling back to system', [
                'channel' => $channel,
                'template_id' => $templateId,
                'event_type' => $payload['event_type'] ?? null,
            ]);
            return ['subject' => $systemTitle, 'body' => $channel === 'sms' ? $systemTitle : $systemMessage];
        }

        if ($mode === 'manual') {
            $subjectTpl = (string) ($spec['subject'] ?? '');
            $bodyTpl = (string) ($spec['body'] ?? ($spec['body_html'] ?? ''));
            return [
                'subject' => $subjectTpl !== '' ? $this->renderString($subjectTpl, $vars) : $systemTitle,
                'body' => $bodyTpl !== ''
                    ? $this->renderString($bodyTpl, $vars)
                    : ($channel === 'sms' ? $systemTitle : $systemMessage),
            ];
        }

        // system (default)
        return [
            'subject' => $systemTitle,
            'body' => $channel === 'sms' ? $systemTitle : $systemMessage,
        ];
    }

    /**
     * @param array<string, mixed> $action
     * @return array<string, mixed>
     */
    private function channelSpec(array $action, string $channel): array
    {
        $content = $action['channel_content'] ?? null;
        if (is_array($content) && isset($content[$channel]) && is_array($content[$channel])) {
            return $content[$channel];
        }

        $legacy = $action['channel_templates'] ?? null;
        if (is_array($legacy) && isset($legacy[$channel]) && is_numeric($legacy[$channel])) {
            return [
                'mode' => 'local',
                'template_id' => (int) $legacy[$channel],
            ];
        }

        return ['mode' => 'system'];
    }

    /**
     * @param array<string, string> $vars
     * @return array{subject: string, body: string}|null
     */
    private function renderLocalTemplate(int $templateId, string $channel, array $vars): ?array
    {
        try {
            $row = $this->connection->fetchAssociative(
                'SELECT id, type, subject, body, is_active
                 FROM fw_message_templates
                 WHERE id = ? LIMIT 1',
                [$templateId]
            );
            if (!$row) {
                return null;
            }
            if (isset($row['is_active']) && !(bool) $row['is_active']) {
                return null;
            }

            $type = strtolower((string) ($row['type'] ?? ''));
            if ($channel === 'email' && $type !== '' && $type !== 'email') {
                $this->logger->warning('Template type mismatch for email channel', [
                    'template_id' => $templateId,
                    'type' => $type,
                ]);
            }
            if ($channel === 'sms' && $type !== '' && $type !== 'sms') {
                $this->logger->warning('Template type mismatch for sms channel', [
                    'template_id' => $templateId,
                    'type' => $type,
                ]);
            }

            $subject = (string) ($row['subject'] ?? '');
            $body = (string) ($row['body'] ?? '');

            return [
                'subject' => $subject !== '' ? $this->renderString($subject, $vars) : '',
                'body' => $body !== '' ? $this->renderString($body, $vars) : '',
            ];
        } catch (Throwable $e) {
            $this->logger->error('Failed to load/render message template', [
                'template_id' => $templateId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * @param array<string, string> $vars
     */
    private function renderString(string $template, array $vars): string
    {
        try {
            // Fresh ArrayLoader per render — templates are dynamic strings from DB/rules
            $name = 'inline';
            $twig = new Environment(new ArrayLoader([$name => $template]), [
                'autoescape' => false,
                'strict_variables' => false,
            ]);
            return $twig->render($name, $vars);
        } catch (Throwable $e) {
            $this->logger->debug('Twig render failed; using simple replace', [
                'error' => $e->getMessage(),
            ]);
            $out = $template;
            foreach ($vars as $key => $value) {
                $out = str_replace('{{' . $key . '}}', $value, $out);
                $out = str_replace('{{ ' . $key . ' }}', $value, $out);
            }
            return $out;
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, string>
     */
    private function buildVariables(array $payload): array
    {
        $after = is_array($payload['after_data'] ?? null) ? $payload['after_data'] : [];
        $before = is_array($payload['before_data'] ?? null) ? $payload['before_data'] : [];
        $eventType = (string) ($payload['event_type'] ?? '');
        $entityType = (string) ($payload['entity_type'] ?? '');
        $entityId = (string) ($payload['entity_id'] ?? '');

        $projectName = (string) ($after['prj_name'] ?? $after['project_name'] ?? $before['prj_name'] ?? '');
        $taskName = (string) ($after['task_name'] ?? $after['name'] ?? $before['task_name'] ?? '');
        $status = (string) ($after['status'] ?? $after['sys_status'] ?? '');
        $prevStatus = (string) ($before['status'] ?? $before['sys_status'] ?? '');
        $comment = (string) ($payload['comment'] ?? '');

        $url = $this->buildUrl($payload);

        $vars = [
            'EVENT_TYPE' => $eventType,
            'EVENT_LABEL' => ucwords(strtolower(str_replace('_', ' ', $eventType))),
            'ENTITY_TYPE' => $entityType,
            'ENTITY_ID' => $entityId,
            'PROJECT_NAME' => $projectName,
            'PROJECT_ID' => (string) ($after['project_id'] ?? ($entityType === 'project' ? $entityId : '')),
            'TASK_NAME' => $taskName,
            'TASK_ID' => (string) ($entityType === 'task' ? $entityId : ($after['task_id'] ?? '')),
            'STATUS' => $status,
            'PREV_STATUS' => $prevStatus,
            'COMMENT' => $comment,
            'URL' => $url,
            'APP_URL' => rtrim((string) ($_ENV['APP_URL'] ?? $_ENV['FRONTEND_URL'] ?? ''), '/'),
        ];

        // Flat string values from after_data for flexibility
        foreach ($after as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $k = strtoupper((string) $key);
                if (!isset($vars[$k])) {
                    $vars[$k] = (string) ($value ?? '');
                }
            }
        }

        return $vars;
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, string> $vars
     */
    private function systemTitle(array $payload, array $vars): string
    {
        $label = $vars['EVENT_LABEL'] !== '' ? $vars['EVENT_LABEL'] : 'Event';
        $name = $vars['PROJECT_NAME'] !== '' ? $vars['PROJECT_NAME'] : $vars['TASK_NAME'];
        return $name !== '' ? "{$label}: {$name}" : $label;
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, string> $vars
     */
    private function systemMessage(array $payload, array $vars, string $title): string
    {
        $comment = trim($vars['COMMENT'] ?? '');
        if ($comment !== '') {
            return $comment;
        }
        $parts = [$title];
        if ($vars['STATUS'] !== '') {
            $line = 'Status: ' . $vars['STATUS'];
            if ($vars['PREV_STATUS'] !== '') {
                $line = 'Status: ' . $vars['PREV_STATUS'] . ' → ' . $vars['STATUS'];
            }
            $parts[] = $line;
        }
        if ($vars['URL'] !== '' && $vars['URL'] !== '/') {
            $parts[] = 'Link: ' . $vars['URL'];
        }
        return implode("\n", $parts);
    }

    /** @param array<string, mixed> $payload */
    private function buildUrl(array $payload): string
    {
        $entityType = (string) ($payload['entity_type'] ?? '');
        $entityId = (int) ($payload['entity_id'] ?? 0);
        $after = is_array($payload['after_data'] ?? null) ? $payload['after_data'] : [];

        if ($entityType === 'project' && $entityId > 0) {
            return "/projects/{$entityId}/detail";
        }
        if ($entityType === 'task' && $entityId > 0) {
            $projectId = (int) ($after['project_id'] ?? 0);
            if ($projectId > 0) {
                return "/tasks/projects/{$projectId}/tasks/{$entityId}";
            }
        }
        return '/';
    }
}
