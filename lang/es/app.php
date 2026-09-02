<?php

/*
|--------------------------------------------------------------------------
| Dashboard UI Language Lines (Spanish)
|--------------------------------------------------------------------------
*/

return [
    // Común / navegación
    'app_name' => 'Plataforma de Presencia',
    'dashboard' => 'Panel',
    'teacher_dashboard' => 'Panel del Profesor',
    'admin_dashboard' => 'Panel del Administrador',
    'logout' => 'Cerrar sesión',
    'login' => 'Iniciar sesión',
    'email' => 'Correo',
    'password' => 'Contraseña',
    'remember' => 'Recuérdame',
    'language' => 'Idioma',
    'welcome' => 'Bienvenido/a, :name',
    'back' => 'Volver',

    // Login
    'login_title' => 'Inicia sesión en la Plataforma de Presencia',
    'login_hint' => 'Usuarios demo (del seeder): admin@presence.test / teacher@presence.test — contraseña "password".',

    // Panel del profesor
    'today_attendance' => 'Asistencia de hoy',
    'class' => 'Clase',
    'student' => 'Estudiante',
    'status' => 'Estado',
    'present' => 'Presente',
    'late' => 'Tarde',
    'absent' => 'Ausente',
    'tapped_at' => 'Tap a las',
    'late_cutoff_note' => 'Tarde = tap después de las :cutoff',
    'no_students' => 'Aún no hay estudiantes en esta clase.',
    'pae_enrolled' => 'PAE',
    'points' => 'Puntos',

    // Panel del administrador
    'school_today' => 'Hoy en la escuela',
    'attendance_count' => 'Asistencia (estudiantes distintos)',
    'pae_breakfast' => 'PAE desayuno',
    'pae_lunch' => 'PAE almuerzo',
    'recycling_today' => 'Reciclaje hoy',
    'recycling_items' => 'Artículos',
    'recycling_points' => 'Puntos otorgados',
    'readers' => 'Lectores',
    'reader' => 'Lector',
    'reader_type' => 'Tipo',
    'active_mode' => 'Modo activo',
    'change_mode' => 'Cambiar modo',
    'mode_updated' => 'Modo del lector actualizado.',
    'nl_query' => 'Consulta en lenguaje natural',
    'nl_query_placeholder' => 'Pregunta p. ej.: ¿Cuántos estudiantes asistieron hoy? / ¿PAE del almuerzo? / ¿reciclaje de esta semana?',
    'ask' => 'Preguntar',
    'nl_query_not_configured' => 'La consulta NL no está configurada (sin GEMINI_API_KEY) — el endpoint lo reporta como bloqueado.',
    'redemption' => 'Canjear recompensa',
    'students' => 'Estudiantes',
    'reward' => 'Recompensa',
    'redeem' => 'Canjear',
    'balance' => 'Saldo',
    'view_parent' => 'Vista del padre/madre',

    // Línea de tiempo del padre
    'parent_timeline' => 'Vista del padre/madre — historial de :name',
    'event_type' => 'Evento',
    'reader_label' => 'Lector',
    'material' => 'Material',
    'no_events' => 'Todavía no hay eventos registrados para este estudiante.',

    // Estados genéricos
    'ok' => 'OK',
    'saving' => 'Guardando…',
    'error_generic' => 'Algo salió mal, reintenta.',
];
