<?php

namespace App\FieldAudit;

/** Tipos de pregunta de una auditoría en terreno. */
class QuestionTypes
{
    public const ALL = ['yes_no', 'scale', 'choice', 'multi_choice', 'number', 'text', 'date', 'photo', 'signature', 'gps'];
    /** Se responden subiendo archivos, no con un valor. */
    public const EVIDENCE = ['photo', 'signature'];
    public const SEVERITIES = ['low', 'medium', 'high', 'critical'];
    public const PHOTO_RULES = ['none', 'optional', 'required', 'on_fail'];

    public const LABELS = [
        'yes_no' => 'Sí / No / N/A', 'scale' => 'Escala', 'choice' => 'Selección única', 'multi_choice' => 'Selección múltiple',
        'number' => 'Número', 'text' => 'Texto', 'date' => 'Fecha', 'photo' => 'Foto', 'signature' => 'Firma', 'gps' => 'Ubicación GPS',
    ];
}
