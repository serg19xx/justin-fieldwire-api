<?php

namespace App\Swagger;

use OpenApi\Annotations as OA;

/**
 * @OA\OpenApi(
 *     @OA\Info(
 *         title="FieldWire API",
 *         description="REST API for FieldWire application - Construction project management system",
 *         version="1.0.0",
 *         @OA\Contact(
 *             name="FieldWire Support",
 *             email="support@fieldwire.com"
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
 *         description="Enter JWT token"
 *     )
 * )
 */

/**
         * @OA\Schema(
 *     schema="EventRule",
         *     type="object",
 *     @OA\Property(property="event_type", type="string", example="PROJECT_CREATED"),
 *     @OA\Property(property="enabled", type="boolean", example=true),
 *     @OA\Property(property="actions", type="array", @OA\Items(type="string"), example={"notify", "log_only"}),
 *     @OA\Property(property="severity", type="string", enum={"critical", "important"}, example="important"),
 *     @OA\Property(property="conditions", type="object", example={"notify_roles": {"admin", "manager"}}),
 *     @OA\Property(property="comment", type="string", example="Rule for project creation events"),
 *     @OA\Property(property="updated_at", type="string", format="date-time"),
 *     @OA\Property(property="updated_by", type="integer", nullable=true)
 * )
 */

/**
         * @OA\Schema(
 *     schema="MessageTemplate",
         *     type="object",
 *     @OA\Property(property="id", type="integer", example=1),
 *     @OA\Property(property="name", type="string", example="Project Created Notification"),
 *     @OA\Property(property="type", type="string", enum={"sms", "email"}, example="email"),
 *     @OA\Property(property="category", type="string", enum={"system", "custom"}, example="system"),
 *     @OA\Property(property="event_type", type="string", example="PROJECT_CREATED"),
 *     @OA\Property(property="subject", type="string", nullable=true, example="New Project: {{project_name}}"),
 *     @OA\Property(property="body", type="string", example="<h2>New Project</h2><p>{{project_name}}</p>"),
 *     @OA\Property(property="variables", type="object", nullable=true, example={"project_name": "Project name"}),
 *     @OA\Property(property="is_editable", type="boolean", example=true),
 *     @OA\Property(property="is_active", type="boolean", example=true),
 *     @OA\Property(property="created_by", type="integer", nullable=true, example=1),
 *     @OA\Property(property="created_at", type="string", format="date-time"),
 *     @OA\Property(property="updated_at", type="string", format="date-time")
         * )
         */
class OpenApiSpec
{
}