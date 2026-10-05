<?php

namespace App\Services\Greenter;

use App\Models\Company;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class CertificateService
{
    /**
     * Get PEM formatted certificate (public + private key) for Greenter.
     */
    public function getCertificatePem(Company $company): string
    {
        $disk = config('factos.storage_disk', 'local');
        $path = $company->certificate_path;

        if ($path) {
            $content = null;
            $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

            if (Storage::disk($disk)->exists($path)) {
                $content = Storage::disk($disk)->get($path);
            } elseif ($disk !== 'local' && Storage::disk('local')->exists($path)) {
                $content = Storage::disk('local')->get($path);
            } elseif (file_exists($path)) {
                $content = file_get_contents($path);
            } else {
                $appStoragePath = storage_path('app/'.ltrim($path, '/'));
                if (file_exists($appStoragePath)) {
                    $content = file_get_contents($appStoragePath);
                }
            }

            if ($content !== false && $content !== null) {
                if (in_array($extension, ['pfx', 'p12'], true)) {
                    return $this->convertPfxToPem($content, (string) $company->certificate_pass);
                }

                return $content;
            }
        }

        // Fallback for tests or local dev when custom cert is not yet uploaded
        $fallback = base_path('/cert.pem');
        if (file_exists($fallback)) {
            $fallbackContent = file_get_contents($fallback);
            if ($fallbackContent !== false) {
                return $fallbackContent;
            }
        }

        throw new RuntimeException("Certificado no encontrado para la empresa {$company->ruc}");
    }

    /**
     * Convert PKCS12 (.pfx/.p12) content to PEM string containing certificate and private key.
     */
    public function convertPfxToPem(string $pfxContent, string $password): string
    {
        $certs = [];
        if (! openssl_pkcs12_read($pfxContent, $certs, $password)) {
            throw new RuntimeException('No se pudo descifrar el certificado PFX. Verifique la contraseña.');
        }

        return ($certs['cert'] ?? '')."\n".($certs['pkey'] ?? '');
    }
}
