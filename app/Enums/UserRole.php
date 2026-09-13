<?php

namespace App\Enums;

enum UserRole: string
{
    case Admin = 'admin';
    case Teacher = 'teacher';

    /**
     * TASK-025 item 5 (spec §11/§12/§30) — student self-service accounts.
     * A student user row is a 1:1 ACCOUNT layer referencing the existing
     * students row (users.student_id); it is never a second student
     * identity. Login works exactly like admin/teacher (session auth);
     * role:student routes gate the self-service views.
     */
    case Student = 'student';

    /**
     * TASK-037 — kitchen (meal-service) staff accounts. They authenticate
     * through the normal login flow but are restricted to the kitchen
     * workflow: the /kitchen glanceable realtime view of meal service.
     * They have no access to admin/teacher/student desks (EnsureRole
     * walls), and their realtime connections receive the meal-service
     * tap frames (ADR-054).
     */
    case Kitchen = 'kitchen';
}
