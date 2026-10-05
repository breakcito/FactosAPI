- En DocumentController.php:19 y DespatchController.php:20, los métodos index() hacen Document::query() y Despatch::query() sin filtrar por las empresas pertenecientes al usuario autenticado ($request->user()->companies). Cualquier usuario puede listar y ver documentos de cualquier otra empresa del sistema.


- En CompanyController.php:51-82, los métodos show(), update() y webhooks() no validan que $company->user_id === $request->user()->id. Cualquier usuario autenticado puede sobrescribir la configuración, certificados y webhooks de empresas ajenas.


- En DocumentController::void() y DespatchController::void(), cualquier usuario autenticado puede solicitar la baja o anulación de documentos de otras empresas.


- Error fatal (TypeError) al reintentar webhooks de Guías de Remisión: En WebhookController.php:14-16:

$webhook->loadMissing(['document']);                                                                                                                                             
        SendWebhookJob::dispatch($webhook->document, $webhook->event)->onQueue('webhooks');                                                                                              
      Cuando el webhook corresponde a una Guía de Remisión (despatch_id poblado, document_id nulo), $webhook->document es null. Al pasarlo a SendWebhookJob::__construct(public Document|
      Despatch $document, ...), PHP lanza un TypeError fatal (código HTTP 500).                                                                                                          

- Cálculo incorrecto del correlativo diario de anulación: En VoidDocumentJob.php:30-33:                                                    

$correlative = (int) (Document::where('company_id', $company>id)->whereNotNull('void_ticket')->whereDate('created_at', now()->toDateString())->count() +1);

Se filtra por created_at (la fecha de creación del comprobante original), no por la fecha en la que se generó la baja. Si hoy se anula una factura creada ayer o la semana pasada, whereDate('created_at', now()) no la cuenta. Si en el mismo día se anulan dos o más facturas de días previos, ambas recibirán el correlativo 1 (RA-YYYYMMDD-00001), y SUNAT rechazará la segunda por número de ticket/comunicación duplicada.                      

-  Corrupción del estado del comprobante si falla la solicitud de baja: En VoidDocumentJob.php:42, si la comunicación de baja falla (por caída de conexión o timeout con SUNAT), se ejecuta:                                                             

$this->document->status = 'failed';

Esto sobrescribe el estado del comprobante que ya estaba formalmente aceptado ante SUNAT, dejándolo en 'failed'. Como DocumentController.php:149 exige que el comprobante esté en estado 'accepted' para poder anularse, el usuario queda completamente bloqueado de reintentar la anulación.


- Los endpoints alias (/credit-notes y /debit-notes) ignoran el tipo de documento si no se envía type_code: En StoreInvoiceRequest.php:111-123, determineTypeCode() solo mira si vino type_code o si la serie comienza con 'B'. Si un usuario consume el endpoint específico POST /api/v1/credit-notes con serie FC01 y no incluye "type_code": "07", determineTypeCode() devuelve '01' (Factura). El comprobante se registra como Factura, omitiendo la validación   obligatoria del objeto note e intentando firmarse como factura de venta.


- Las Guías de Remisión en estado waiting_sunat nunca se reintentan: En RetryWaitingDocumentsCommand.php:32, el comando programado cada minuto en console.php:5 solo hace Document::query(). Si una Despatch (guía) sufre un timeout temporal con el  web service de SUNAT y pasa a waiting_sunat, queda en el olvido y jamás es reintentada.

- Tickets de anulación pendientes (void_pending) sin verificación posterior: En VoidDocumentJob.php:59-85, las comunicaciones de baja (RA y RC) devuelven un ticket asíncrono. Si SUNAT responde con código 98 (ticket en proceso) o la consulta inmediata    falla por red, el job termina y el documento se queda en void_pending indefinidamente. No hay ningún comando programado ni job diferido que consulte el estado del ticket más       adelante.

- Anulación de Guía de Remisión no comunicada a SUNAT: En DespatchController.php:214-220, void() únicamente actualiza la base de datos local a 'voided' y responde 200 OK diciendo "Guía de remisión anulada exitosamente", sin generar  XML de baja, sin invocar ningún servicio de SUNAT ni guardar constancia alguna.

- Permite emitir Facturas (01) a DNI o sin documento: En StoreInvoiceRequest.php:81, se permite client.doc_type en ['0', '1', '4', '6', '7']. Para una Factura (01), la normativa SUNAT exige obligatoriamente doc_type = '6' (RUC) y   longitud de 11 dígitos. Al no validarse a nivel de Request, el comprobante se firma y se envía a SUNAT solo para ser rechazado tributariamente.

- Moneda EUR no contemplada en conversión a letras: En StoreInvoiceRequest.php:40, se acepta currency en PEN,USD,EUR. Sin embargo, en NumeroALetras.php:32-35, el match solo contempla 'USD' y el resto pasa a 'SOLES'. Una factura   en euros termina con la leyenda "CON 00/100 SOLES".

- Validación de RUC demasiado restrictiva: En ServiceController.php:43 y StoreCompanyRequest.php:20, la regex /^(10|20)\d{9}$/ rechaza RUCs válidos de personas naturales extranjeras que inician con 15 o 17.              

- Disco hardcodeado en CertificateService: En CertificateService.php:66-68, se usa Storage::disk('local')->exists($path), ignorando config('factos.storage_disk') si el sistema se configura con almacenamiento en S3 o     MinIO según lo previsto en el README.md.







