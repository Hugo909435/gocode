<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MessageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // Le contenu est soit du texte brut (messages utilisateur) soit du JSON encodé
        // (événements agent). On tente un décodage pour retourner une structure cohérente.
        $decoded = json_decode($this->content, true);

        return [
            'id'         => $this->id,
            'session_id' => $this->session_id,
            'role'       => $this->role,
            'type'       => $this->type,
            'content'    => $decoded ?? $this->content,
            'meta'       => $this->meta,
            'created_at' => $this->created_at,
        ];
    }
}
