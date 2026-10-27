<?php

namespace App\Models;

use App\Models\Concerns\HasUlid;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BusinessOnboarding extends Model
{
    use HasUlid;

    public const DOCUMENTS = ['personal_pan', 'citizenship', 'national_id', 'business_tax', 'business_registration', 'municipality_registration', 'ward_registration'];

    public const BUSINESS_DOCUMENTS = [
        'business_tax' => 'vat_pan_document',
        'business_registration' => 'business_registration_document',
        'municipality_registration' => 'municipality_registration_document',
        'ward_registration' => 'ward_registration_document',
    ];

    protected $guarded = ['id'];

    protected $hidden = ['documents', 'form_data'];

    protected function casts(): array
    {
        return ['documents' => 'array', 'form_data' => 'array', 'submitted_at' => 'datetime', 'reviewed_at' => 'datetime', 'revision' => 'integer'];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function summary(): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'revision' => $this->revision,
            'fields' => $this->form_data,
            'documents' => collect($this->documents)->map(fn ($file, $kind) => [
                'kind' => $kind, 'name' => $file['name'], 'size' => $file['size'], 'mime_type' => $file['mime_type'], 'received' => true,
            ])->values()->all(),
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'rejection_reason' => $this->rejection_reason,
        ];
    }
}
