<?php

namespace App\Models;

use Database\Factories\CompanyFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property int $user_id
 * @property string $ruc
 * @property string $business_name
 * @property string|null $trademark_name
 * @property string|null $address
 * @property string|null $ubigeo
 * @property string|null $department
 * @property string|null $province
 * @property string|null $district
 * @property string $sol_user
 * @property string $sol_pass
 * @property string $certificate_path
 * @property string $certificate_pass
 * @property string|null $webhook_url
 * @property string|null $webhook_secret
 * @property bool $is_production
 * @property bool $is_active
 * @property bool $email_notifications_active
 * @property array<string>|null $company_copy_emails
 * @property bool $send_to_client_email
 * @property array<string, mixed>|null $email_template_settings
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 * @property-read Collection<int, Document> $documents
 */
class Company extends Model
{
    /** @use HasFactory<CompanyFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'user_id',
        'ruc',
        'business_name',
        'trademark_name',
        'address',
        'ubigeo',
        'department',
        'province',
        'district',
        'establishment_code',
        'sol_user',
        'sol_pass',
        'client_id',
        'client_secret',
        'certificate_path',
        'certificate_pass',
        'webhook_url',
        'webhook_secret',
        'is_production',
        'is_active',
        'email_notifications_active',
        'company_copy_emails',
        'send_to_client_email',
        'email_template_settings',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'sol_pass',
        'client_secret',
        'certificate_pass',
        'webhook_secret',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sol_pass' => 'encrypted',
            'client_secret' => 'encrypted',
            'certificate_pass' => 'encrypted',
            'is_production' => 'boolean',
            'is_active' => 'boolean',
            'email_notifications_active' => 'boolean',
            'send_to_client_email' => 'boolean',
            'company_copy_emails' => 'array',
            'email_template_settings' => 'array',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<Document, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    /**
     * @return HasMany<WebhookDelivery, $this>
     */
    public function webhookDeliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class);
    }
}
