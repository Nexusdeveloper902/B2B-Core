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

    // Shell / chrome (TASK-005 UI passover)
    'skip_to_content' => 'Saltar al contenido',
    'primary_nav' => 'Principal',
    'footer_note' => 'Consola de operaciones — asistencia, comidas PAE y recompensas por reciclaje del día escolar.',
    'demo_credentials' => 'Credenciales demo (seeder)',
    'env' => 'Entorno',

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

    // Escritorio de emparejamiento (TASK-011)
    'action' => 'Acción',
    'pairing_desk' => 'Emparejar tarjetas',
    'pairing_desk_intro' => 'Arma un emparejamiento para un estudiante y luego acerca una tarjeta NUEVA al lector dentro de la ventana. El vínculo tarjeta-estudiante siempre es una decisión de admin tomada aquí — nunca en el lector.',
    'pairing_arm' => 'Armar emparejamiento',
    'pairing_armed_for' => 'Armada para :name',
    'pairing_seconds_left' => 'quedan :s s',
    'pairing_go_tap' => 'Ahora acerca una tarjeta NUEVA al lector.',
    'pairing_no_session' => 'No hay sesión de emparejamiento armada.',
    'pairing_expired' => 'La ventana expiró sin tarjeta — arma de nuevo.',
    'pairing_success' => 'Tarjeta :uid emparejada con :name.',
    'pairing_status' => 'Estado del emparejamiento',
    'pairing_recent' => 'Tarjetas emparejadas recientemente',
    'pairing_uid' => 'UID de tarjeta',
    'pairing_paired_at' => 'Emparejada',
    'pairing_none_yet' => 'Aún no hay tarjetas emparejadas.',
    'pairing_rejected' => 'La tarjeta :uid fue rechazada — :reason. Toca una tarjeta DISTINTA, o ejecuta ./run unpair en el servidor para que toda tarjeta vuelva a ser emparejable.',
    'pairing_reason_already_paired' => 'esa tarjeta ya está emparejada',
    'current_card' => 'Tarjeta actual',
    'no_card' => 'sin tarjeta',

    // Estados genéricos
    'ok' => 'OK',
    'saving' => 'Guardando…',
    'error_generic' => 'Algo salió mal, reintenta.',
];
