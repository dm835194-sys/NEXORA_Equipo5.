<?php

return [
    'demo' => (bool) env('DEMO_MODE', true),
    'demo_password' => env('DEMO_PASSWORD', 'TurnoLab2026!'),
    'mail_enabled' => (bool) env('TURNOLAB_MAIL_ENABLED', false),
    'slot_capacity' => 20,
    'qr_seconds' => 90,
    'roles' => ['student' => 'Estudiante', 'teacher' => 'Docente', 'technician' => 'Técnico', 'admin' => 'Coordinación'],
    'states' => ['available' => 'Disponible', 'prepared' => 'Preparado', 'loaned' => 'Prestado', 'pending_review' => 'Revisión pendiente', 'quarantine' => 'Cuarentena', 'maintenance' => 'Mantenimiento', 'missing' => 'Faltante', 'reserved' => 'Reservada', 'ready' => 'Kit preparado', 'active' => 'En préstamo', 'review' => 'En revisión', 'completed' => 'Completada', 'cancelled' => 'Cancelada', 'open' => 'Abierta', 'resolved' => 'Resuelta'],
];
