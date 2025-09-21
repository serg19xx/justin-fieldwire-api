<?php

namespace App\Swagger;

use OpenApi\Annotations as OA;

/**
 * @OA\OpenApi(
 *     @OA\Info(
 *         title="FieldWire API",
 *         version="1.0.0",
 *         description="REST API for FieldWire application - Construction project management system",
 *         @OA\Contact(
 *             email="support@fieldwire.com",
 *             name="FieldWire Support"
 *         )
 *     ),
 *     @OA\Server(
 *         url="http://localhost:8000",
 *         description="Development server"
 *     ),
 *     @OA\Server(
 *         url="https://fieldwire.medicalcontractor.ca",
 *         description="Production server"
 *     ),
 *     @OA\SecurityScheme(
 *         securityScheme="bearerAuth",
 *         type="http",
 *         scheme="bearer",
 *         bearerFormat="JWT",
 *         description="JWT Authorization header using the Bearer scheme. Example: 'Authorization: Bearer {token}'"
 *     ),
 *     @OA\Tag(
 *         name="Health",
 *         description="API health and status endpoints"
 *     ),
 *     @OA\Tag(
 *         name="Authentication",
 *         description="User authentication and authorization endpoints"
 *     ),
 *     @OA\Tag(
 *         name="Workers",
 *         description="Worker management and invitation system endpoints"
 *     ),
 *     @OA\Tag(
 *         name="Profile",
 *         description="User profile management endpoints"
 *     ),
 *     @OA\Tag(
 *         name="Projects",
 *         description="Project management endpoints"
 *     ),
 *     @OA\Tag(
 *         name="Tasks",
 *         description="Project tasks management endpoints"
 *     ),
     *     @OA\Tag(
     *         name="Project Team",
     *         description="Project team management endpoints"
     *     ),
     *     @OA\Tag(
     *         name="Event Rules",
     *         description="Event rules configuration endpoints"
     *     ),
     *     @OA\Tag(
     *         name="Event Logs",
     *         description="Event logging and audit trail management endpoints"
     *     ),
     *     @OA\Tag(
     *         name="N8N Integration",
     *         description="Endpoints for n8n workflow integration"
     *     ),
     *     @OA\Schema(
     *         schema="EventLog",
     *         type="object",
     *         @OA\Property(property="id", type="integer", example=123),
     *         @OA\Property(property="occurred_at", type="string", format="date-time", example="2025-09-10T14:30:00Z"),
     *         @OA\Property(property="tenant_id", type="string", example="tenant_123"),
     *         @OA\Property(property="entity_type", type="string", example="task"),
     *         @OA\Property(property="entity_id", type="integer", example=456),
     *         @OA\Property(property="entity_version", type="integer", example=1),
     *         @OA\Property(property="event_type", type="string", example="STATUS_CHANGED"),
     *         @OA\Property(property="severity", type="string", enum={"critical", "important"}, example="important"),
     *         @OA\Property(property="actor_type", type="string", enum={"user", "system", "api"}, example="user"),
     *         @OA\Property(property="actor_id", type="integer", example=789),
     *         @OA\Property(property="correlation_id", type="string", example="550e8400-e29b-41d4-a716-446655440000"),
     *         @OA\Property(property="changed_fields", type="array", @OA\Items(type="string"), example={"status", "updated_at"}),
     *         @OA\Property(property="before_data", type="object", example={"status": "pending"}),
     *         @OA\Property(property="after_data", type="object", example={"status": "completed"}),
     *         @OA\Property(property="comment", type="string", example="Status changed from pending to completed"),
     *         @OA\Property(property="ip", type="string", example="192.168.1.1"),
     *         @OA\Property(property="user_agent", type="string", example="Mozilla/5.0...")
     *     ),
     *     @OA\Schema(
     *         schema="OutboxEvent",
     *         type="object",
     *         @OA\Property(property="id", type="integer", example=123),
     *         @OA\Property(property="event_log_id", type="integer", example=456),
     *         @OA\Property(property="event_type", type="string", example="STATUS_CHANGED"),
     *         @OA\Property(property="payload", type="object", description="Event payload data"),
     *         @OA\Property(property="attempts", type="integer", example=0),
     *         @OA\Property(property="last_error", type="string", example="Connection timeout"),
     *         @OA\Property(property="created_at", type="string", format="date-time", example="2025-09-10T14:30:00Z")
     *     )
     * )
 */
class OpenApiSpec
{
    // Этот класс служит только для определения базовой OpenAPI спецификации
    // через аннотации. Реальная логика не требуется.
}