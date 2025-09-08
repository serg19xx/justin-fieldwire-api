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
 *     )
 * )
 */
class OpenApiSpec
{
    // Этот класс служит только для определения базовой OpenAPI спецификации
    // через аннотации. Реальная логика не требуется.
}