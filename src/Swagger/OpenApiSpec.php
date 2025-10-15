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
     *     @OA\Tag(
     *         name="Languages",
     *         description="Language management endpoints"
     *     ),
     *     @OA\Tag(
     *         name="Worker Languages",
     *         description="Worker language proficiency management endpoints"
     *     ),
     *     @OA\Tag(
     *         name="Two-Factor",
     *         description="Two-factor authentication endpoints for SMS and email verification"
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
     *     ),
     *     @OA\Schema(
     *         schema="User",
     *         type="object",
     *         @OA\Property(property="id", type="integer", example=47),
     *         @OA\Property(property="email", type="string", example="pm1@example.com"),
     *         @OA\Property(property="first_name", type="string", example="John"),
     *         @OA\Property(property="last_name", type="string", example="Doe"),
     *         @OA\Property(property="phone", type="string", example="+1234567890"),
     *         @OA\Property(property="role_id", type="integer", example=1),
     *         @OA\Property(property="job_title", type="string", example="Project Manager"),
     *         @OA\Property(property="status", type="boolean", example=true),
     *         @OA\Property(property="status_reason", type="string", example="Training"),
     *         @OA\Property(property="status_details", type="string", example="Additional training required"),
     *         @OA\Property(property="additional_info", type="string", example="Additional information"),
     *         @OA\Property(property="avatar_url", type="string", example="http://localhost:8000/api/v1/avatar?file=user_47_avatar.png"),
     *         @OA\Property(property="full_img_url", type="string", example="http://localhost:8000/api/v1/full-image?file=user_47_full.png"),
     *         @OA\Property(property="two_factor_enabled", type="boolean", example=false),
     *         @OA\Property(property="last_login", type="string", format="date-time", example="2025-09-10T14:30:00Z"),
     *         @OA\Property(property="status_changed_at", type="string", format="date-time", example="2025-09-10T14:30:00Z"),
     *         @OA\Property(property="status_end_at", type="string", format="date-time", example="2025-10-14T00:00:00Z"),
     *         @OA\Property(property="dob", type="string", format="date", example="1990-01-15"),
     *         @OA\Property(property="gender", type="string", enum={"Male", "Female"}, example="Male"),
     *         @OA\Property(property="nationality", type="string", example="Canadian"),
     *         @OA\Property(property="country_of_origin", type="string", example="Canada"),
         *         @OA\Property(property="workforce_group", type="string", example="Construction"),
         *         @OA\Property(property="city", type="string", example="Toronto"),
         *         @OA\Property(property="emergency", ref="#/components/schemas/EmergencyContact"),
         *         @OA\Property(property="languages", type="array", @OA\Items(
     *             @OA\Property(property="language_id", type="integer", example=1),
     *             @OA\Property(property="language_name", type="string", example="English"),
     *             @OA\Property(property="prof_level", type="string", enum={"Basic", "Intermidiate", "Fluent"}, example="Fluent"),
     *             @OA\Property(property="worker_id", type="integer", example=47)
     *         )),
     *         @OA\Property(property="created_at", type="string", format="date-time", example="2025-09-10T14:30:00Z"),
     *         @OA\Property(property="updated_at", type="string", format="date-time", example="2025-09-10T14:30:00Z"),
     *         @OA\Property(property="invitation_status", type="string", example="completed"),
     *         @OA\Property(property="invitation_sent_at", type="string", format="date-time", example="2025-09-10T14:30:00Z"),
     *         @OA\Property(property="invitation_expires_at", type="string", format="date-time", example="2025-09-17T14:30:00Z"),
     *         @OA\Property(property="invited_by", type="integer", example=1),
     *         @OA\Property(property="registration_completed_at", type="string", format="date-time", example="2025-09-10T14:30:00Z"),
     *         @OA\Property(property="invitation_attempts", type="integer", example=0),
     *         @OA\Property(property="last_reminder_sent_at", type="string", format="date-time", example="2025-09-10T14:30:00Z"),
     *         @OA\Property(property="archived_at", type="string", format="date-time", example=null),
     *         @OA\Property(property="role_code", type="string", example="PM"),
     *         @OA\Property(property="role_name", type="string", example="Project Manager"),
     *         @OA\Property(property="role_category", type="string", example="Management"),
     *         @OA\Property(property="role_description", type="string", example="Manages construction projects")
         *     )
         * )
         * 
         * @OA\Schema(
         *     schema="EmergencyContact",
         *     type="object",
         *     @OA\Property(property="primary_contact_name", type="string", example="John Smith"),
         *     @OA\Property(property="primary_contact_phone", type="string", example="(555) 123-4567"),
         *     @OA\Property(property="primary_contact_relationship", type="string", example="Wife"),
         *     @OA\Property(property="secondary_contact_name", type="string", example="Jane Doe"),
         *     @OA\Property(property="secondary_contact_phone", type="string", example="(555) 987-6543"),
         *     @OA\Property(property="secondary_contact_relationship", type="string", example="Sister"),
         *     @OA\Property(property="blood_type", type="string", example="A+"),
         *     @OA\Property(property="allergies", type="string", example="Penicillin, dust"),
         *     @OA\Property(property="medical_conditions", type="string", example="Diabetes, asthma"),
         *     @OA\Property(property="medications", type="string", example="Insulin, inhaler"),
         *     @OA\Property(property="medical_notes", type="string", example="Patient has severe allergies to shellfish"),
         *     @OA\Property(property="insurance_company", type="string", example="Blue Cross Blue Shield"),
         *     @OA\Property(property="policy_number", type="string", example="BC123456789"),
         *     @OA\Property(property="insurance_emergency_contact", type="string", example="(555) 800-HELP")
         * )
         * 
         * @OA\Schema(
         *     schema="ProfessionalData",
         *     type="object",
         *     @OA\Property(property="work_experience", type="array", @OA\Items(
         *         @OA\Property(property="company", type="string", example="ABC Construction"),
         *         @OA\Property(property="position", type="string", example="Senior Project Manager"),
         *         @OA\Property(property="start_date", type="string", format="date", example="2020-01-15"),
         *         @OA\Property(property="end_date", type="string", format="date", example="2023-12-31"),
         *         @OA\Property(property="description", type="string", example="Managed large construction projects")
         *     )),
         *     @OA\Property(property="education", type="array", @OA\Items(
         *         @OA\Property(property="institution", type="string", example="University of Toronto"),
         *         @OA\Property(property="degree", type="string", example="Bachelor of Engineering"),
         *         @OA\Property(property="field", type="string", example="Civil Engineering"),
         *         @OA\Property(property="graduation_year", type="integer", example=2018)
         *     )),
         *     @OA\Property(property="certifications", type="array", @OA\Items(
         *         @OA\Property(property="name", type="string", example="PMP Certification"),
         *         @OA\Property(property="issuer", type="string", example="PMI"),
         *         @OA\Property(property="issue_date", type="string", format="date", example="2022-06-15"),
         *         @OA\Property(property="expiry_date", type="string", format="date", example="2025-06-15")
         *     )),
         *     @OA\Property(property="safety_certifications", type="array", @OA\Items(
         *         @OA\Property(property="name", type="string", example="OSHA 30-Hour Construction"),
         *         @OA\Property(property="issuer", type="string", example="OSHA"),
         *         @OA\Property(property="issue_date", type="string", format="date", example="2023-01-10"),
         *         @OA\Property(property="expiry_date", type="string", format="date", example="2026-01-10")
         *     )),
         *     @OA\Property(property="skills", type="array", @OA\Items(
         *         @OA\Property(property="category", type="string", example="Technical"),
         *         @OA\Property(property="skills", type="array", @OA\Items(type="string"), example={"Project Management", "AutoCAD", "Budget Planning"})
         *     )),
         *     @OA\Property(property="equipment", type="array", @OA\Items(
         *         @OA\Property(property="name", type="string", example="Personal Protective Equipment"),
         *         @OA\Property(property="type", type="string", example="Safety"),
         *         @OA\Property(property="certification_required", type="boolean", example=true)
         *     )),
         *     @OA\Property(property="professional_summary", type="string", example="Experienced construction project manager with 10+ years in the industry")
         * )
         */
class OpenApiSpec
{
    // Этот класс служит только для определения базовой OpenAPI спецификации
    // через аннотации. Реальная логика не требуется.
}