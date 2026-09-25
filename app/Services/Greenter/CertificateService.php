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
        $path = $this->resolvePath($company->certificate_path);

        if ($path && file_exists($path)) {
            $content = file_get_contents($path);
            if ($content === false) {
                throw new RuntimeException("No se pudo leer el archivo de certificado en: {$path}");
            }

            if (str_ends_with(strtolower($path), '.pfx') || str_ends_with(strtolower($path), '.p12')) {
                return $this->convertPfxToPem($content, $company->certificate_pass);
            }

            return $content;
        }

        // Fallback for tests or local dev when custom cert is not yet uploaded
        $fallback = base_path('docs/4-greenter/c-api-with-greenter-example-2/resources/cert.pem');
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

    private function resolvePath(?string $path): ?string
    {
        if (empty($path)) {
            return null;
        }

        if (file_exists($path)) {
            return $path;
        }

        if (Storage::disk('local')->exists($path)) {
            return Storage::disk('local')->path($path);
        }

        $appStoragePath = storage_path('app/'.ltrim($path, '/'));
        if (file_exists($appStoragePath)) {
            return $appStoragePath;
        }

        return null;
    }
}
