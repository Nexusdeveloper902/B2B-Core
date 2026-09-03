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

    // Endpoint de consulta en lenguaje natural
    'nlq_not_configured' => 'La consulta en lenguaje natural no está configurada: falta GEMINI_API_KEY (bloqueada, no fallida).',
    'nlq_invalid_key' => 'Google rechazó el GEMINI_API_KEY configurado (inválido o revocado). Crea una clave nueva en Google AI Studio, ponla en .env y verifica con: ./run llm-check',
    'nlq_region_unsupported' => 'Google rechaza las llamadas a la API de Gemini desde esta red o región («User location is not supported»). La clave sí es válida: ejecuta ./run llm-check desde esta máquina y consulta la página «Available regions» de la API de Gemini.',
    'nlq_model_not_found' => 'El GEMINI_MODEL configurado no existe para esta cuenta o versión de la API. Usa el valor por defecto (gemini-3.1-flash-lite) y verifica con: ./run llm-check',
    'nlq_rate_limited' => 'La cuota del modelo de lenguaje se agotó, reintenta más tarde.',
    'nlq_unavailable' => 'El servicio del modelo de lenguaje no está disponible, reintenta más tarde.',

    // Genérico
    'forbidden_role' => 'No tienes permiso para realizar esta acción.',
    'not_found' => 'Recurso no encontrado',
];
