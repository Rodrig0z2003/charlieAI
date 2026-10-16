<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class KnowledgeChunk extends Model
{
    // Forzar conexión central para que los tenants lean la base global
    protected $connection = 'mysql'; 

    protected $fillable = [
        'document_name',
        'is_system_rule', // <-- Agrégalo aquí
        'chunk_content',
        'embedding',
    ];

    protected $casts = [
        'embedding' => 'array',
        'is_system_rule' => 'boolean', // Recomendado para que Laravel lo maneje como true/false nativo
    ];
}