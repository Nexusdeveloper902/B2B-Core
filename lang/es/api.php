<?php

/*
|--------------------------------------------------------------------------
| API Language Lines (Spanish) — device-facing + endpoint messages
|--------------------------------------------------------------------------
*/

return [
    // Autenticación de lectores
    'missing_bearer_token' => 'Falta el token de portador (bearer)',
    'invalid_bearer_token' => 'Token de portador (bearer) no válido',

    // Endpoint de tap
    'card_not_recognized' => 'Tarjeta no reconocida',
    'card_not_active' => 'La tarjeta no está activa',

    // Endpoint de clasificación
    'event_not_owned_by_reader' => 'El evento no pertenece a este lector',
    'event_not_recycling' => 'El evento no es un depósito de reciclaje',
    'classifier_unavailable' => 'El clasificador de materiales no está disponible, reintenta más tarde',

    // Endpoint de canje
    'insufficient_points' => 'Puntos insuficientes: faltan :shortfall',

    // TASK-025 item 7 — reglas del catálogo de recompensas (spec §19/§20)
    'reward_inactive' => 'Esta recompensa está inactiva por ahora',
    'reward_out_of_stock' => 'Esta recompensa está agotada',
    'duplicate_redemption' => 'Este canje ya se recibió hace un momento — no se cobraron puntos de nuevo',

    // TASK-025 item 2 — endpoints de captura botella-primero (spec §3/§32)
    'no_pending_capture' => 'No hay una captura pendiente disponible para este lector',
    'capture_not_owned_by_reader' => 'La captura no pertenece a este lector',
    'capture_already_associated' => 'La captura ya fue asociada a una tarjeta',
    'event_expired' => 'El evento de tap es demasiado antiguo para clasificarlo',

    // Endpoint de emparejamiento de tarjetas (TASK-010)
    'pairing_no_active_session' => 'No hay ninguna sesión de emparejamiento activa',
    'pairing_card_already_paired' => 'La tarjeta ya está emparejada',

    // Endpoint de consulta en lenguaje natural
    'nlq_not_configured' => 'La consulta en lenguaje natural no está configurada: falta DEEPSEEK_API_KEY (bloqueada, no fallida).',
    'nlq_invalid_key' => 'DeepSeek rechazó el DEEPSEEK_API_KEY configurado (inválido o revocado). Crea una clave nueva en platform.deepseek.com, ponla en .env y verifica con: ./run llm-check',
    'nlq_insufficient_balance' => 'La clave es válida pero el saldo de la cuenta de DeepSeek está vacío (pago por uso, sin capa gratuita). Recarga en platform.deepseek.com y verifica con: ./run llm-check',
    'nlq_model_not_found' => 'El DEEPSEEK_MODEL configurado no existe para esta cuenta o versión de la API. Usa el valor por defecto (deepseek-flash) y verifica con: ./run llm-check',
    'nlq_rate_limited' => 'La cuota del modelo de lenguaje se agotó, reintenta más tarde.',
    'nlq_unavailable' => 'El servicio del modelo de lenguaje no está disponible, reintenta más tarde.',

    // TASK-027 — puerta de matrícula PAE (los lectores del programa
    // alimentario rechazan estudiantes no matriculados; mensaje para
    // el dispositivo, localizado por Accept-Language).
    'pae_not_enrolled' => ':student no está matriculado en el programa alimentario PAE',
    'pae_unknown_student' => 'este estudiante',

    // TASK-027 — gestión de estudiantes (crear + importar CSV)
    'student_duplicate' => 'Ya existe un estudiante llamado :name en :class',
    'student_created' => 'Estudiante :name creado',
    'students_import_unreadable' => 'No se pudo leer el archivo subido como CSV',
    'students_import_bad_header' => 'Faltan columnas obligatorias en el encabezado (name, grade, class)',
    'students_import_no_rows' => 'El CSV no contiene filas de datos',
    'students_import_too_many_rows' => 'El CSV supera el máximo de :max filas',
    'students_import_row_incomplete' => 'A la fila le falta nombre o grado',
    'students_import_unknown_class' => 'La clase :class no existe',
    'students_import_summary' => ':created estudiante(s) creados, :failed fila(s) fallidas',

    // TASK-027 — desvinculación por tarjeta
    'card_unpaired' => 'Tarjeta desvinculada de :student — la credencial vuelve a estar fresca',

    // TASK-027 (brecha E1) — ruta de imagen de captura
    'capture_image_missing' => 'No hay imagen almacenada para esta captura',

    // TASK-029 — creación de clases (el escritorio de estudiantes)
    'class_created' => 'Clase :name creada',
    'class_duplicate' => 'Ya existe una clase llamada :name',

    // TASK-030-B — aprovisionamiento de lectores (API keys de un solo vistazo)
    'reader_created' => 'Lector :label creado',
    'reader_deleted' => 'Lector :label eliminado',
    'reader_key_notice' => 'API key (cópiala ahora — no se vuelve a mostrar)',
    'reader_key_rotated' => 'API key rotada para :label',

    // TASK-030-A — accesos automáticos de estudiantes (el password
    // temporal se muestra una sola vez, como una API key de lector).
    'student_account_notice' => 'Acceso listo: :email / contraseña inicial :password — debe cambiarse en el primer inicio de sesión',
    'student_account_exists' => 'Este estudiante ya tiene un acceso (:email)',
    'password_change_required' => 'Debes definir una nueva contraseña antes de continuar.',

    // TASK-038 — aprovisionamiento de personal (el escritorio /admin/staff).
    // La contraseña temporal se muestra una sola vez, como un acceso de
    // estudiante o una API key de lector.
    'staff_created' => 'Acceso de personal :name creado',
    'staff_duplicate' => 'Ya existe un acceso con el correo :email',
    'staff_account_notice' => 'Acceso listo: :email / contraseña temporal :password — debe cambiarse en el primer inicio de sesión',
    'staff_classes_teacher_only' => 'Solo los profesores pueden tener clases a cargo',

    // Genérico
    'forbidden_role' => 'No tienes permiso para realizar esta acción.',
    'not_found' => 'Recurso no encontrado',

    /* TASK-037 — el motor de servicio de comidas (ADR-053): mensajes
       para dispositivos, localizados por Accept-Language. Los rechazos
       de comida siempre NOMBRAN la comida. */
    'meal_breakfast' => 'Desayuno',
    'meal_lunch' => 'Almuerzo',
    'pae_meal_served' => ':student recibió :meal',
    'pae_weekend' => 'Hoy no hay servicio de comidas — el programa alimentario funciona de lunes a viernes',
    'pae_out_of_window' => 'Ahora no se está sirviendo ninguna comida. Desayuno :breakfast · Almuerzo :lunch',
    'pae_window_overlap' => 'Las ventanas de servicio se solapan — revisa el escritorio de configuración',
    'pae_no_student' => 'Esta tarjeta no está vinculada a ningún estudiante',
    'pae_not_enrolled_meal' => ':student no está inscrito para el :meal',
    'pae_no_attendance' => ':student debe registrar asistencia en clase antes de recibir el :meal',
    'pae_duplicate' => ':student ya recibió el :meal hoy',

    // Motivos de rechazo estables (etiquetas para reportes/cocina)
    'pae_reason_weekend' => 'Fin de semana (sin servicio)',
    'pae_reason_out_of_window' => 'Fuera de la ventana de servicio',
    'pae_reason_window_overlap' => 'Ventanas solapadas',
    'pae_reason_no_student' => 'Tarjeta sin estudiante',
    'pae_reason_not_enrolled' => 'No inscrito',
    'pae_reason_no_attendance' => 'Sin asistencia previa',
    'pae_reason_duplicate' => 'Comida duplicada',

    // Superficie de configuración (ADR-055)
    'settings_empty' => 'No se recibió ninguna configuración',
    'settings_invalid' => 'La configuración no es válida',
    'settings_saved' => 'Configuración guardada — aplica de inmediato',
    'settings_unknown_key' => 'Clave de configuración desconocida: :key',
    'settings_windows_overlap' => 'Las ventanas de desayuno y almuerzo no deben solaparse',
];
