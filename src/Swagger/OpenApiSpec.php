<?php

namespace App\Swagger;

use OpenApi\Annotations as OA;

/**
 * @OA\Info(
 *     title="FieldWire API",
 *     version="1.0.0",
 *     description="REST API for FieldWire application - Field management and communication platform",
 *     @OA\Contact(
 *         email="support@fieldwire.com",
 *         name="FieldWire Support",
 *         url="https://fieldwire.com/support"
 *     ),
 *     @OA\License(
 *         name="Proprietary",
 *         url="https://fieldwire.com/license"
 *     )
 * )
 * 
 * @OA\Server(
 *     url="https://api.fieldwire.com",
 *     description="Production server"
 * )
 * 
 * @OA\Server(
 *     url="http://localhost:8000",
 *     description="Development server"
 * )
 * 
 * @OA\SecurityScheme(
 *     securityScheme="bearerAuth",
 *     type="http",
 *     scheme="bearer",
 *     bearerFormat="JWT"
 * )
 * 
 * @OA\Tag(
 *     name="Authentication",
 *     description="User authentication and authorization endpoints"
 * )
 * 
 * @OA\Tag(
 *     name="Profile",
 *     description="User profile management"
 * )
 * 
 * @OA\Tag(
 *     name="Two-Factor",
 *     description="Two-factor authentication management"
 * )
 * 
 * @OA\Tag(
 *     name="Health",
 *     description="API health and status endpoints"
 * )
 * 
 * @OA\Tag(
 *     name="Database",
 *     description="Database management and setup"
 * )
 * 
 * @OA\PathItem(
 *     path="/",
 *     summary="API Root"
 * )
 * 
 * @OA\PathItem(
 *     path="/health",
 *     summary="Health Check",
 *     @OA\Get(
 *         summary="Get API health status",
 *         tags={"Health"},
 *         @OA\Response(
 *             response=200,
 *             description="API is healthy",
 *             @OA\JsonContent(
 *                 @OA\Property(property="status", type="string", example="healthy"),
 *                 @OA\Property(property="timestamp", type="string", format="date-time")
 *             )
 *         )
 *     )
 * )
 */
class OpenApiSpec
{
    // Base OpenAPI specification
}
