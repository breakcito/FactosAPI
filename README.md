# FactosAPI — Multi-Tenant Electronic Invoicing Core (SUNAT - Perú)

> **API REST Headless construida sobre Laravel 13, MySQL y Greenter.**
> Microservicio centralizado, multi-inquilino (*multi-tenant*) y multi-sistema, diseñado para que cualquier software externo (ERPs, tiendas online, POS, sistemas de gestión o scripts independientes) emita, firme, valide y reporte comprobantes electrónicos a SUNAT mediante comunicación asíncrona, colas y webhooks.
>
> Diseñada para despliegue contenerizado en VPS mediante **Coolify**. Cero interfaces visuales: comunicación 100% JSON, XML y binarios (PDF/CDR).

---

## Directiva para Modelos de IA (AI System Prompt)

Si estás leyendo o ejecutando tareas sobre este repositorio como un asistente de inteligencia artificial o desarrollador automatizado, debes seguir de forma obligatoria estas directivas:

1. **Uso Exclusivo de Documentación Local (`docs/`):** Tienes acceso completo a la carpeta `docs/`. Antes de inventar esquemas tributarios, validar códigos de afectación al IGV o instanciar clases de Greenter, **consulta de forma estricta los archivos locales**:
   - `docs/1-facturacion-electronica/`: Normas técnicas oficiales SUNAT, firma digital UBL 2.1, webservices SOAP/REST, reglas de baja y resúmenes diarios.
   - `docs/2-api-facturacion-example/`: Referencia viva basada en `Facturalo.pe`. Usa sus ejemplos para armar los JSON de facturas (gravada, exonerada, inafecta, exportación, detracciones, anticipos), boletas, notas de crédito/débito, guías de remisión y esquemas de webhooks.
   - `docs/3-apis-peru/`: Endpoints de consulta para RUC, DNI y tipos de cambio (SBS, SUNAT e histórico).
   - `docs/4-greenter/`: Referencia directa de la librería Greenter (paquetes XML, firma dsig, envío WS y parsing de CDR).
2. **Desacoplamiento Absoluto:** Cada emisor tributario (`Company`) opera de forma aislada. La recepción del JSON nunca debe bloquearse esperando a SUNAT; se responde inmediatamente con `202 Accepted` y se procesa en segundo plano.
3. **Idempotencia y No Re-firma:** Si SUNAT cae o entra en timeout, el XML ya generado y firmado digitalmente **no debe volver a generarse ni cambiar de digest/hash**. Se reintenta exclusivamente el paquete binario firmado.
4. **Desarrollo para Desarrolladores:** Los endpoints deben ser intuitivos, consistentes en nombres de variables (snake_case estándar), con validaciones formales en `FormRequest` que retornen mensajes de error legibles y códigos HTTP semánticos (`200`, `201`, `202`, `401`, `422`, `500`).

---

## 1. Arquitectura General y Tecnologías

- **Framework:** Laravel 13 (Modo API pura, sin sesiones web ni vistas Blade).
- **Base de Datos:** MySQL 8.x (CharSet `utf8mb4`, Collation `utf8mb4_0900_ai_ci`).
- **Autenticación:** Laravel Sanctum (Tokens Bearer por usuario/sistema).
- **Librería Core Tributaria:** Greenter (`greenter/ws`, `greenter/xml`, `greenter/report`).
- **Infraestructura & Despliegue:** Dockerfile optimizado para PHP 8.3+, orquestado vía **Coolify** en servidor VPS (gestión de variables de entorno, workers de Horizon y volúmenes persistentes para certificados y archivos).
- **Almacenamiento de Comprobantes:** Driver `storage` de Laravel (Local persistente Coolify o S3/MinIO compatible si se establece en las variables de entorno) estructurado por empresa: `storage/app/tenants/{ruc}/{year}/{month}/{tipo-serie-correlativo}.ext`.

---

## 2. Autenticación y Multi-Tenancy

Cualquier sistema cliente que consuma FactosAPI debe autenticarse usando cabeceras Bearer Token:

```http
Authorization: Bearer <token_obtenido>
Accept: application/json
Content-Type: application/json

```

### Gestión de Usuarios y Tokens

* `POST /api/v1/auth/register`: Registro de cuenta de usuario/sistema (`name`, `email`, `password`).
* `POST /api/v1/auth/login`: Autenticación con credenciales, retornando el token Bearer (`plainTextToken`).
* `POST /api/v1/auth/logout`: Revocación del token actual.

### Modelo Multi-Empresa

Un usuario puede administrar una o más empresas (`companies`). Cada comprobante emitido se asocia al RUC de la empresa emisora que tenga asignada:

* Credenciales secundarias SOL (Usuario y Clave SOL).
* Certificado digital (`.pfx` o `.pem` con su contraseña).
* Configuración de Webhook particular (URL y Secreto HMAC).
* Parámetros de notificación por correo electrónico.

---

## 3. Modelo de Datos Relacional (MySQL)

```
users (Cuentas que consumen la API)
├── id (BIGINT PK)
├── name (VARCHAR 255)
├── email (VARCHAR 255, UNIQUE)
├── password (VARCHAR 255)
├── created_at (TIMESTAMP)
└── updated_at (TIMESTAMP)

companies (Emisores Tributarios / Tenants)
├── id (UUID PK)
├── user_id (BIGINT, FK -> users.id)
├── ruc (VARCHAR 11, UNIQUE)
├── business_name (VARCHAR 255)
├── trademark_name (VARCHAR 255, NULLABLE)
├── sol_user (VARCHAR 50)
├── sol_pass (TEXT - cifrado con Crypt::encryptString)
├── certificate_path (VARCHAR 500)
├── certificate_pass (TEXT - cifrado)
├── webhook_url (VARCHAR 500, NULLABLE)
├── webhook_secret (VARCHAR 100, NULLABLE)
├── is_production (BOOLEAN, DEFAULT false)
├── is_active (BOOLEAN, DEFAULT true)
│   -- Configuración de Notificaciones por Correo
├── email_notifications_active (BOOLEAN, DEFAULT false)
├── company_copy_emails (JSON, NULLABLE) -- Lista de emails para copia interna ["admin@empresa.com", "conta@empresa.com"]
├── send_to_client_email (BOOLEAN, DEFAULT false)
├── email_template_settings (JSON, NULLABLE) -- Asunto, colores, logo personalizado
├── created_at (TIMESTAMP)
└── updated_at (TIMESTAMP)

documents (Facturas, Boletas, Notas)
├── id (UUID PK)
├── company_id (UUID, FK -> companies.id)
├── external_id (VARCHAR 100, INDEX) -- ID provisto por el sistema cliente para trazabilidad
├── type_code (CHAR 2) -- '01': Factura, '03': Boleta, '07': Nota Crédito, '08': Nota Débito
├── series (CHAR 4) -- Ej: F001, B001
├── correlative (INT)
├── issue_date (DATE)
├── issue_time (TIME)
├── due_date (DATE, NULLABLE)
├── currency (CHAR 3) -- 'PEN', 'USD'
├── client_doc_type (CHAR 1) -- '6': RUC, '1': DNI, '4': Carnet, etc. (Catálogo 06)
├── client_doc_number (VARCHAR 15)
├── client_name (VARCHAR 255)
├── client_address (VARCHAR 255, NULLABLE)
├── client_email (VARCHAR 255, NULLABLE)
├── total_taxable (DECIMAL 12,2, DEFAULT 0.00) -- Gravadas
├── total_unaffected (DECIMAL 12,2, DEFAULT 0.00) -- Inafectas
├── total_exonerated (DECIMAL 12,2, DEFAULT 0.00) -- Exoneradas
├── total_igv (DECIMAL 12,2, DEFAULT 0.00)
├── total_icbper (DECIMAL 12,2, DEFAULT 0.00)
├── total_discount (DECIMAL 12,2, DEFAULT 0.00)
├── total (DECIMAL 12,2)
├── status (ENUM: 'pending', 'signed', 'waiting_sunat', 'accepted', 'rejected', 'failed')
├── sunat_code (VARCHAR 10, NULLABLE) -- Ej: '0' (Aceptado), '2000' (Rechazo)
├── sunat_description (TEXT, NULLABLE)
├── sunat_notes (JSON, NULLABLE) -- Alertas/observaciones preventivas del CDR
├── hash (VARCHAR 255, NULLABLE) -- DigestValue de la firma digital UBL
├── xml_path (VARCHAR 500, NULLABLE)
├── cdr_path (VARCHAR 500, NULLABLE)
├── pdf_path (VARCHAR 500, NULLABLE)
├── retry_count (INT, DEFAULT 0)
├── created_at (TIMESTAMP)
└── updated_at (TIMESTAMP)

document_items (Detalle de Documentos)
├── id (BIGINT PK)
├── document_id (UUID, FK -> documents.id)
├── internal_code (VARCHAR 50, NULLABLE)
├── description (VARCHAR 500)
├── unit_code (CHAR 3) -- 'NIU', 'ZZ', etc. (Catálogo 03)
├── quantity (DECIMAL 12,4)
├── unit_value (DECIMAL 12,4) -- Valor unitario (sin IGV)
├── unit_price (DECIMAL 12,4) -- Precio unitario (con IGV)
├── igv_type (CHAR 2) -- Catálogo 07 (ej: '10', '20', '30')
├── igv_amount (DECIMAL 12,2)
├── total (DECIMAL 12,2)
└── attributes (JSON, NULLABLE)

webhook_deliveries (Trazabilidad y Auditoría de Webhooks)
├── id (UUID PK)
├── company_id (UUID, FK -> companies.id)
├── document_id (UUID, FK -> documents.id)
├── event (VARCHAR 50) -- 'document.accepted', 'document.rejected', 'document.waiting'
├── payload (JSON)
├── response_code (INT, NULLABLE)
├── response_body (TEXT, NULLABLE)
├── status (ENUM: 'pending', 'delivered', 'failed')
├── attempts (INT, DEFAULT 0)
└── created_at (TIMESTAMP)

```

---

## 4. Endpoints y Casos de Uso Documentados

### A. Emisión Asíncrona de Comprobantes

* **Endpoint:** `POST /api/v1/invoices`
* **Comportamiento:** Valida reglas tributarias con el catálogo local (`docs/2-api-facturacion-example/b.facturas/`), inserta el registro con estado `pending`, encola el Job de firma/envío y responde al instante con `202 Accepted`.

#### Payload de Ejemplo (Envío de Factura):

```json
{
  "company_id": "9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d",
  "external_id": "ORDER-98541",
  "series": "F001",
  "correlative": 452,
  "issue_date": "2026-09-25",
  "issue_time": "14:32:00",
  "currency": "PEN",
  "client": {
    "doc_type": "6",
    "doc_number": "20600055231",
    "name": "SERVICIOS TECNOLOGICOS S.A.C.",
    "address": "Av. Los Sauces 452, Trujillo",
    "email": "facturacion@servicios.pe"
  },
  "items": [
    {
      "internal_code": "PROD-01",
      "description": "Consultoría e integración de software",
      "unit_code": "ZZ",
      "quantity": 1,
      "unit_value": 1000.00,
      "unit_price": 1180.00,
      "igv_type": "10",
      "igv_amount": 180.00,
      "total": 1180.00
    }
  ],
  "totals": {
    "taxable": 1000.00,
    "igv": 180.00,
    "total": 1180.00
  }
}

```

#### Respuesta Inmediata (`202 Accepted`):

```json
{
  "status": "success",
  "message": "Comprobante recibido y encolado para procesamiento.",
  "data": {
    "id": "c1f7b1e4-8451-4043-9ce4-d2fb2d35c24e",
    "external_id": "ORDER-98541",
    "document": "F001-452",
    "status": "pending"
  }
}

```

---

### B. Módulo de Consultas Auxiliares (RUC, DNI y Tipo de Cambio)

Para evitar que los sistemas clientes contraten múltiples APIs externas, FactosAPI expone endpoints centralizados que resuelven los datos apoyándose en la documentación de `docs/3-apis-peru/`.

* **Consulta de DNI (RENIEC):**
* `GET /api/v1/services/dni/{number}`
* Retorna: nombres, apellido paterno, apellido materno y código de verificación.


* **Consulta de RUC (SUNAT):**
* `GET /api/v1/services/ruc/{number}`
* Retorna: razón social, estado (ACTIVO), condición (HABIDO), dirección fiscal, ubigeo.


* **Tipo de Cambio (SBS / SUNAT):**
* `GET /api/v1/services/exchange-rate?source=sunat&date=2026-09-25`
* Retorna: cotización de compra y venta de la fecha consultada.



---

### C. Módulo de Webhooks (Notificación Push al Sistema Cliente)

Cuando el worker procesa el comprobante con Greenter y obtiene respuesta de SUNAT, dispara una petición HTTP POST al `webhook_url` de la empresa.

#### Cabeceras de Seguridad:

```http
POST /api/tu-webhook-listener HTTP/1.1
Content-Type: application/json
X-Factos-Signature: sha256=1e58f276c70144f6f3630f9a2b8e312bc87...
X-Factos-Event: document.accepted

```

*El sistema cliente debe calcular `hash_hmac('sha256', $bodyRaw, $webhook_secret)` para verificar la autenticidad.*

#### Payload del Evento (`document.accepted` o `document.waiting`):

```json
{
  "event": "document.accepted",
  "timestamp": "2026-09-25T19:35:10Z",
  "data": {
    "id": "c1f7b1e4-8451-4043-9ce4-d2fb2d35c24e",
    "external_id": "ORDER-98541",
    "document_type": "01",
    "series": "F001",
    "correlative": 452,
    "status": "accepted",
    "sunat": {
      "code": "0",
      "description": "La Factura numero F001-452, ha sido aceptada."
    },
    "hash": "p6J9l0qX2/87Hs12k...",
    "links": {
      "xml": "[https://mi-api.coolify.app/api/v1/documents/c1f7b.../xml](https://mi-api.coolify.app/api/v1/documents/c1f7b.../xml)",
      "cdr": "[https://mi-api.coolify.app/api/v1/documents/c1f7b.../cdr](https://mi-api.coolify.app/api/v1/documents/c1f7b.../cdr)",
      "pdf": "[https://mi-api.coolify.app/api/v1/documents/c1f7b.../pdf](https://mi-api.coolify.app/api/v1/documents/c1f7b.../pdf)"
    }
  }
}

```

---

### D. Módulo de Correos Automáticos (Mailable Preparado)

El sistema incluye la arquitectura completa para despacho de correos en colas (`SendInvoiceEmailJob`), activable mediante la configuración de la empresa en BD:

1. **Condición de Disparo:** Se evalúa cuando el estado pasa a `accepted`.
2. **Destinatarios Configurables:**
* Si `send_to_client_email` es `true` y el comprobante tiene `client_email`, se envía como destinatario principal.
* Si `company_copy_emails` contiene direcciones válidas, se envían como copias (`CC` o `BCC`).


3. **Contenido:** Plantilla minimalista con adjuntos automáticos del XML firmado y el PDF generado. Si no hay servidor SMTP configurado aún, el Job valida el flag `email_notifications_active = false` y descarta el envío de forma limpia sin generar excepciones.

---

## 5. Arquitectura de Colas y Resiliencia ante Caídas de SUNAT

```
Job: ProcessDocumentJob (Queue: 'high')
  │
  ├── 1. Mapea datos al modelo Greenter (Invoice, Items, Taxes).
  ├── 2. Genera UBL 2.1 y firma digitalmente.
  ├── 3. Almacena el XML firmado en Storage y guarda el Hash.
  └── 4. Despacha Job: SendDocumentToSunatJob (Queue: 'sunat')
        │
        ├── Éxito (CDR recibido, code: 0) -> Status: accepted -> Despacha Webhook y Email.
        ├── Rechazo SUNAT (code: 2000+)   -> Status: rejected -> Despacha Webhook.
        └── Timeout / Caída de SUNAT:
            ├── Actualiza Status a 'waiting_sunat'
            ├── Despacha Webhook con estado 'document.waiting'
            └── Reintenta el envío del XML ya firmado con backoff exponencial
                (1m, 5m, 15m, 1h, 4h).

```

---

## 6. Despliegue en VPS con Coolify

La aplicación incluye la configuración necesaria para desplegarse mediante Dockerfile estándar de Laravel en Coolify:

1. **Variables de Entorno Clave (`.env`):**
```env
APP_ENV=production
APP_DEBUG=false
APP_URL=[https://factos.tudominio.com](https://factos.tudominio.com)

DB_CONNECTION=mysql
DB_HOST=mysql_factos
DB_PORT=3306
DB_DATABASE=factos_api
DB_USERNAME=coolify_user
DB_PASSWORD=secret_password

QUEUE_CONNECTION=database # O redis si se agrega contenedor redis
FILESYSTEM_DISK=local

```


2. **Volumen Persistente:** En Coolify, mapear un volumen persistente hacia `/var/www/html/storage/app` para conservar los certificados digitales (`.pfx`) y los comprobantes generados (`XML`, `CDR`, `PDF`).
3. **Procesos en Segundo Plano (Supervisord / Workers):** El contenedor debe ejecutar:
* `php artisan queue:work --queue=high,sunat,webhooks,emails --tries=3 --timeout=120`
* Cron de Laravel: `* * * * * php artisan schedule:run >> /dev/null 2>&1` (para barrido de comprobantes en estado `waiting_sunat`).



```