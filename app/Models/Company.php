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

use Illuminate\Database\Eloquent\SoftDeletes;

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
 * @property Carbon|null $deleted_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 * @property-read Collection<int, Document> $documents
 */
class Company extends Model
{
    /** @use HasFactory<CompanyFactory> */
    use HasFactory, HasUuids, SoftDeletes;

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
        'mail_host',
        'mail_port',
        'mail_username',
        'mail_password',
        'mail_encryption',
        'mail_from_address',
        'mail_from_name',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'sol_pass',
        'client_secret',
        'certificate_pass',
        'webhook_secret',
        'mail_password',
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
            'mail_password' => 'encrypted',
            'mail_port' => 'integer',
            'is_production' => 'boolean',
            'is_active' => 'boolean',
            'email_notifications_active' => 'boolean',
            'send_to_client_email' => 'boolean',
            'company_copy_emails' => 'array',
            'email_template_settings' => 'array',
        ];
    }

    public function hasCustomMailConfig(): bool
    {
        return !empty($this->mail_username) && !empty($this->mail_password);
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

    /**
     * Create a standard SUNAT Beta test company cloned from master or defaults.
     */
    public static function createTestCompanyForUser(User $user): self
    {
        $template = self::query()->where('is_production', false)->first();

        $attributes = [
            'user_id' => $user->id,
            'ruc' => $template?->ruc ?? '20000000001',
            'business_name' => $template?->business_name ?? 'EMPRESA DE PRUEBA SUNAT S.A.C.',
            'trademark_name' => $template?->trademark_name ?? 'FACTOS BETA TEST',
            'address' => $template?->address ?? 'AV. LOS TESTERS 123 - URB. INDUSTRIAL',
            'ubigeo' => $template?->ubigeo ?? '150101',
            'department' => $template?->department ?? 'LIMA',
            'province' => $template?->province ?? 'LIMA',
            'district' => $template?->district ?? 'LIMA',
            'establishment_code' => $template?->establishment_code ?? '0000',
            'sol_user' => $template?->sol_user ?? 'MODDATOS',
            'sol_pass' => $template?->sol_pass ?? 'moddatos',
            'client_id' => $template?->client_id ?? 'test-85e5b0ae-255c-4891-a595-0b98c65c9854',
            'client_secret' => $template?->client_secret ?? 'test-Hty/M6QshYvPgItX2P0+Kw==',
            'certificate_path' => $template?->certificate_path ?? 'cert.pem',
            'certificate_pass' => $template?->certificate_pass ?? '123456',
            'webhook_url' => $template?->webhook_url ?? 'https://webhook.site/demo-factos-receipt',
            'is_production' => false,
            'is_active' => true,
            'email_notifications_active' => $template?->email_notifications_active ?? true,
            'company_copy_emails' => $template?->company_copy_emails ?? [
                'contabilidad@empresa-prueba.pe',
                'gerencia@empresa-prueba.pe',
            ],
            'send_to_client_email' => $template?->send_to_client_email ?? true,
            'email_template_settings' => $template?->email_template_settings ?? [
                'color' => '#1E40AF',
                'footer_text' => 'Gracias por su preferencia - Comprobante electrónico emitido con Factos API',
            ],
            'mail_host' => $template?->mail_host ?? 'smtp.gmail.com',
            'mail_port' => $template?->mail_port ?? 587,
            'mail_username' => $template?->mail_username ?? 'facturacion.empresa.prueba@gmail.com',
            'mail_encryption' => $template?->mail_encryption ?? 'tls',
            'mail_from_address' => $template?->mail_from_address ?? 'facturacion.empresa.prueba@gmail.com',
            'mail_from_name' => $template?->mail_from_name ?? 'Facturación - Empresa de Prueba S.A.C.',
        ];

        if ($template?->mail_password) {
            $attributes['mail_password'] = $template->mail_password;
        }

        return self::create($attributes);
    }
}
