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
    'nlq_model_not_found' => 'El DEEPSEEK_MODEL configurado no existe para esta cuenta o versión de la API. Usa el valor por defecto (deepseek-v4-flash) y verifica con: ./run llm-check',
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

    // Genérico
    'forbidden_role' => 'No tienes permiso para realizar esta acción.',
    'not_found' => 'Recurso no encontrado',
];
