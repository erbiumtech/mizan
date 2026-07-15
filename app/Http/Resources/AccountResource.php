<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AccountResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'type' => $this->type,
            'normal_balance' => $this->normal_balance,
            'parent_id' => $this->parent_id,
            'is_active' => $this->is_active,
            'allow_manual_entry' => $this->allow_manual_entry,
            'description' => $this->description,
            'balance' => (float) $this->balance,
            'parent' => $this->whenLoaded('parent', fn () => [
                'id' => $this->parent->id,
                'code' => $this->parent->code,
                'name' => $this->parent->name,
            ]),
            'children' => $this->when(
                $this->relationLoaded('childrenRecursive') || $this->relationLoaded('children'),
                fn () => AccountResource::collection(
                    $this->relationLoaded('childrenRecursive') ? $this->childrenRecursive : $this->children
                )
            ),
            'children_count' => $this->whenCounted('children'),
            'lines_count' => $this->whenCounted('lines'),
            'calculated_balance' => $this->when(
                $this->relationLoaded('children') || $this->relationLoaded('childrenRecursive'),
                fn () => $this->calculated_balance
            ),
        ];
    }
}
