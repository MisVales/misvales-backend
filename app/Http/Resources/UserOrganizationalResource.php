<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserOrganizationalResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->public_id ?? $this->id,
            'name'           => $this->name,
            'email'          => $this->email,
            'role'           => [
                'code' => $this->role->code ?? null,
                'name' => $this->role->name ?? null,
            ],
            'scope'          => [
                'type'      => $this->role->scope ?? null,
                'branch_id' => $this->branch->public_id ?? null,
            ],
            'status'         => $this->state,
            'context_version'=> $this->context_version,
            'created_at'     => $this->created_at,
            'updated_at'     => $this->updated_at,
        ];
    }
}