<?php
if (!defined('ABSPATH')) { exit; }
if (!current_user_can(SPF_Roles::INVOICES) && !current_user_can('manage_options')) {
    wp_die(esc_html__('No tienes permisos para gestionar el área fiscal.', 'sistema-para-farmacias'));
}

global $wpdb;
$docs = SPF_Database::table('invoices');
$sales = SPF_Database::table('sales');
$customers = SPF_Database::table('customers');

$adapter = SPF_Fiscal_Manager::active_adapter();
$signer = SPF_Fiscal_Signing_Manager::active_signer();
$provider = SPF_Fiscal_Signing_Manager::active_credentials_provider();
$validator = SPF_Fiscal_Validation_Manager::active_validator();
$validation_resources = SPF_Fiscal_Validation_Manager::resources();
$batch_builder = SPF_Fiscal_Batch_Manager::active_builder('daily_summary');
$transport = SPF_Fiscal_Transport_Manager::active_transport();
$beta_transport = SPF_Fiscal_Transport_Manager::beta_transport();
$beta_profile = SPF_Fiscal_Transport_Manager::beta_profile();
$beta_scope = array_map('sanitize_key', (array)($beta_profile['scope'] ?? []));
$beta_enabled = $beta_transport && method_exists($beta_transport, 'enabled') && call_user_func([$beta_transport, 'enabled']);
$electronic = SPF_Config::electronic_invoicing();
$authority = sanitize_text_field((string)($electronic['authority'] ?? __('Autoridad fiscal', 'sistema-para-farmacias')));
$issuer = SPF_Fiscal_Settings::issuer();

$notice = SPF_Fiscal_Manager::consume_notice();
$signature_notice = SPF_Fiscal_Signing_Manager::consume_notice();
$validation_notice = SPF_Fiscal_Validation_Manager::consume_notice();
$batch_notice = SPF_Fiscal_Batch_Manager::consume_notice();
$batch_preview = SPF_Fiscal_Batch_Manager::consume_preview();
$transport_notice = SPF_Fiscal_Transport_Manager::consume_notice();

$internal_labels = SPF_Documents::statuses();
$signature_labels = SPF_Fiscal_Signing_Manager::statuses();
$validation_labels = SPF_Fiscal_Validation_Manager::statuses();
$transport_labels = SPF_Fiscal_Transport_Manager::statuses();
$beta_labels = SPF_Fiscal_Transport_Manager::beta_statuses();
$fiscal_labels = SPF_Documents::fiscal_statuses();
$has_xsd = !empty($validation_resources['xsd_path']);
$has_xsl = !empty($validation_resources['xsl_path']);

$persisted_batches = SPF_Fiscal_Batch_Repository::latest(20);
$rows = $wpdb->get_results(
    "SELECT i.*,s.sale_number,s.total,s.created_at sale_date,c.name customer_name,c.business_name,c.document_number customer_document_number
     FROM $docs i
     LEFT JOIN $sales s ON s.id=i.sale_id
     LEFT JOIN $customers c ON c.id=s.customer_id
     ORDER BY i.id DESC LIMIT 200",
    ARRAY_A
);
?>
<div class="wrap spf-wrap">
    <h1><?php esc_html_e('Fiscal', 'sistema-para-farmacias'); ?></h1>

    <?php foreach (array_filter([$notice,$signature_notice,$validation_notice,$batch_notice,$transport_notice]) as $message):
        $notice_class = $message['type'] === 'error' ? 'error' : ($message['type'] === 'warning' ? 'warning' : 'success'); ?>
        <div class="notice notice-<?php echo esc_attr($notice_class); ?> is-dismissible"><p><?php echo esc_html($message['message']); ?></p></div>
    <?php endforeach; ?>

    <div class="spf-panel">
        <h2><?php esc_html_e('Cadena fiscal', 'sistema-para-farmacias'); ?></h2>
        <p><?php esc_html_e('Preflight → firma XMLDSig → validación XSD/XSL → dry-run. Los comprobantes agrupables pasan por lotes fiscales persistentes. El beta nunca modifica el estado fiscal de producción.', 'sistema-para-farmacias'); ?></p>
        <div class="spf-config-summary">
            <span><strong><?php esc_html_e('País','sistema-para-farmacias'); ?></strong><?php echo esc_html(SPF_Config::get('name', SPF_Config::country_code())); ?></span>
            <span><strong><?php esc_html_e('Autoridad','sistema-para-farmacias'); ?></strong><?php echo esc_html($authority); ?></span>
            <span><strong><?php esc_html_e('Adaptador','sistema-para-farmacias'); ?></strong><?php echo esc_html($adapter ? $adapter->code() : __('No disponible','sistema-para-farmacias')); ?></span>
            <span><strong><?php esc_html_e('Firmador','sistema-para-farmacias'); ?></strong><?php echo esc_html($signer ? $signer->code() : __('No disponible','sistema-para-farmacias')); ?></span>
            <span><strong><?php esc_html_e('Validador','sistema-para-farmacias'); ?></strong><?php echo esc_html($validator ? $validator->code() : __('No disponible','sistema-para-farmacias')); ?></span>
            <span><strong><?php esc_html_e('Builder diario','sistema-para-farmacias'); ?></strong><?php echo esc_html($batch_builder ? $batch_builder->code() : __('No disponible','sistema-para-farmacias')); ?></span>
            <span><strong><?php esc_html_e('Transporte local','sistema-para-farmacias'); ?></strong><?php echo esc_html($transport ? ($transport->code().' · '.$transport->environment()) : __('No disponible','sistema-para-farmacias')); ?></span>
            <span><strong><?php esc_html_e('Emisor','sistema-para-farmacias'); ?></strong><?php echo esc_html($issuer['legal_name'] ?: __('Sin configurar','sistema-para-farmacias')); ?></span>
        </div>
        <?php if (!$issuer['tax_id'] || !$issuer['legal_name'] || !$issuer['address']): ?>
            <p class="spf-message error"><?php esc_html_e('Completa primero la identidad fiscal del emisor en Configuración.','sistema-para-farmacias'); ?> <a href="<?php echo esc_url(admin_url('admin.php?page=spf-settings')); ?>"><?php esc_html_e('Ir a Configuración','sistema-para-farmacias'); ?></a></p>
        <?php endif; ?>
        <p class="description"><?php esc_html_e('Certificado: SPF_FISCAL_P12_PATH / SPF_FISCAL_P12_PASSWORD. Validadores oficiales locales: SPF_FISCAL_XSD_PATH / SPF_FISCAL_XSL_PATH. Ninguna clave ni paquete oficial se guarda dentro del plugin.','sistema-para-farmacias'); ?></p>
        <?php if ($beta_enabled): ?>
            <p class="spf-message warning"><strong><?php echo esc_html(sprintf(__('%s beta habilitado.','sistema-para-farmacias'), $authority)); ?></strong> <?php esc_html_e('Úsalo únicamente con datos de prueba y de forma puntual. No equivale a una emisión ni aceptación fiscal de producción.','sistema-para-farmacias'); ?></p>
        <?php else: ?>
            <p class="description"><?php esc_html_e('El transporte beta permanece bloqueado por defecto y requiere activación y confirmación explícitas fuera del plugin.','sistema-para-farmacias'); ?></p>
        <?php endif; ?>
    </div>

    <div class="spf-panel">
        <h2><?php esc_html_e('Matriz de conformidad','sistema-para-farmacias'); ?></h2>
        <div class="spf-config-summary">
            <span><strong><?php esc_html_e('Preflight UBL','sistema-para-farmacias'); ?></strong><span class="spf-badge ok"><?php esc_html_e('Implementado','sistema-para-farmacias'); ?></span></span>
            <span><strong><?php esc_html_e('Firma local','sistema-para-farmacias'); ?></strong><span class="spf-badge ok"><?php esc_html_e('Verificación local','sistema-para-farmacias'); ?></span></span>
            <span><strong><?php esc_html_e('XSD','sistema-para-farmacias'); ?></strong><span class="spf-badge <?php echo $has_xsd ? 'ok' : 'neutral'; ?>"><?php echo esc_html($has_xsd ? __('Configurado','sistema-para-farmacias') : __('Pendiente','sistema-para-farmacias')); ?></span></span>
            <span><strong><?php esc_html_e('XSL','sistema-para-farmacias'); ?></strong><span class="spf-badge <?php echo $has_xsl ? 'ok' : 'neutral'; ?>"><?php echo esc_html($has_xsl ? __('Configurado','sistema-para-farmacias') : __('Pendiente','sistema-para-farmacias')); ?></span></span>
            <span><strong><?php esc_html_e('Lote diario','sistema-para-farmacias'); ?></strong><span class="spf-badge <?php echo $batch_builder ? 'ok' : 'neutral'; ?>"><?php echo esc_html($batch_builder ? __('Persistencia local','sistema-para-farmacias') : __('No configurado','sistema-para-farmacias')); ?></span></span>
            <span><strong><?php esc_html_e('Beta remoto','sistema-para-farmacias'); ?></strong><span class="spf-badge <?php echo $beta_enabled ? 'ok' : 'neutral'; ?>"><?php echo esc_html($beta_enabled ? __('Habilitado para pruebas','sistema-para-farmacias') : __('Bloqueado','sistema-para-farmacias')); ?></span></span>
            <span><strong><?php esc_html_e('Producción','sistema-para-farmacias'); ?></strong><span class="spf-badge danger"><?php esc_html_e('Bloqueada','sistema-para-farmacias'); ?></span></span>
        </div>
    </div>

    <div class="spf-panel">
        <h2><?php esc_html_e('Lote fiscal diario','sistema-para-farmacias'); ?></h2>
        <p><?php esc_html_e('El preview muestra únicamente comprobantes nuevos aún no agrupados. Persistir crea un snapshot inmutable con secuencia estable; repetir sin nuevos comprobantes reutiliza el último lote. Ninguna de estas acciones firma o transmite.','sistema-para-farmacias'); ?></p>
        <div class="spf-form-actions">
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="spf-inline-form">
                <?php wp_nonce_field('spf_fiscal_daily_summary_preview'); ?>
                <input type="hidden" name="action" value="spf_fiscal_daily_summary_preview">
                <label><strong><?php esc_html_e('Fecha de emisión','sistema-para-farmacias'); ?></strong> <input type="date" name="reference_date" value="<?php echo esc_attr(wp_date('Y-m-d')); ?>" required></label>
                <button class="button" <?php disabled(!$batch_builder); ?>><?php esc_html_e('Generar preview','sistema-para-farmacias'); ?></button>
            </form>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="spf-inline-form" onsubmit="return confirm('<?php echo esc_js(__('Persistir el lote congela los comprobantes incluidos en un snapshot local. Todavía no se enviará. ¿Continuar?','sistema-para-farmacias')); ?>');">
                <?php wp_nonce_field('spf_fiscal_daily_summary_persist'); ?>
                <input type="hidden" name="action" value="spf_fiscal_daily_summary_persist">
                <label><strong><?php esc_html_e('Fecha de emisión','sistema-para-farmacias'); ?></strong> <input type="date" name="reference_date" value="<?php echo esc_attr(wp_date('Y-m-d')); ?>" required></label>
                <button class="button button-primary" <?php disabled(!$batch_builder); ?>><?php esc_html_e('Crear lote persistente','sistema-para-farmacias'); ?></button>
            </form>
        </div>
        <?php if ($batch_preview): ?>
            <div class="spf-config-summary" style="margin-top:16px">
                <span><strong><?php esc_html_e('Siguiente identificador','sistema-para-farmacias'); ?></strong><code><?php echo esc_html($batch_preview['identifier']); ?></code></span>
                <span><strong><?php esc_html_e('Fecha documentos','sistema-para-farmacias'); ?></strong><?php echo esc_html($batch_preview['reference_date']); ?></span>
                <span><strong><?php esc_html_e('Comprobantes nuevos','sistema-para-farmacias'); ?></strong><?php echo esc_html((string)$batch_preview['line_count']); ?></span>
                <span><strong><?php esc_html_e('SHA-256 XML','sistema-para-farmacias'); ?></strong><code><?php echo esc_html(substr((string)$batch_preview['sha256'],0,16).'…'); ?></code></span>
            </div>
            <?php foreach ((array)($batch_preview['warnings'] ?? []) as $warning): ?><p class="spf-message warning"><?php echo esc_html($warning); ?></p><?php endforeach; ?>
        <?php endif; ?>
    </div>

    <div class="spf-panel">
        <h2><?php esc_html_e('Lotes fiscales persistidos','sistema-para-farmacias'); ?></h2>
        <div class="spf-table-scroll spf-table-wide">
            <table class="widefat striped">
                <thead><tr><th><?php esc_html_e('Identificador','sistema-para-farmacias'); ?></th><th><?php esc_html_e('Fecha','sistema-para-farmacias'); ?></th><th><?php esc_html_e('Comprobantes','sistema-para-farmacias'); ?></th><th><?php esc_html_e('Interno','sistema-para-farmacias'); ?></th><th><?php esc_html_e('Firma','sistema-para-farmacias'); ?></th><th><?php esc_html_e('Validación','sistema-para-farmacias'); ?></th><th><?php esc_html_e('Transporte','sistema-para-farmacias'); ?></th><th><?php esc_html_e('Beta','sistema-para-farmacias'); ?></th><th><?php esc_html_e('Fiscal','sistema-para-farmacias'); ?></th><th><?php esc_html_e('Creado','sistema-para-farmacias'); ?></th></tr></thead>
                <tbody>
                <?php if (!$persisted_batches): ?><tr><td colspan="10"><?php esc_html_e('Todavía no hay lotes persistidos.','sistema-para-farmacias'); ?></td></tr><?php endif; ?>
                <?php foreach ((array)$persisted_batches as $batch):
                    $batch_internal=sanitize_key($batch['internal_status']??'prepared');
                    $batch_signature=sanitize_key($batch['signature_status']??'not_signed');
                    $batch_validation=sanitize_key($batch['validation_status']??'not_validated');
                    $batch_transport=sanitize_key($batch['transport_status']??'not_prepared');
                    $batch_beta=sanitize_key($batch['beta_status']??'not_tested');
                    $batch_fiscal=sanitize_key($batch['fiscal_status']??'not_sent'); ?>
                    <tr>
                        <td><strong><?php echo esc_html($batch['identifier']); ?></strong><br><small><?php echo esc_html($batch['batch_type']); ?></small></td>
                        <td><?php echo esc_html($batch['reference_date']); ?><br><small><?php echo esc_html(sprintf(__('Secuencia %d','sistema-para-farmacias'),(int)$batch['sequence'])); ?></small></td>
                        <td><?php echo esc_html((string)$batch['line_count']); ?></td>
                        <td><?php echo esc_html($internal_labels[$batch_internal] ?? $batch_internal); ?></td>
                        <td><?php echo esc_html($signature_labels[$batch_signature] ?? $batch_signature); ?></td>
                        <td><?php echo esc_html($validation_labels[$batch_validation] ?? $batch_validation); ?></td>
                        <td><?php echo esc_html($transport_labels[$batch_transport] ?? $batch_transport); ?></td>
                        <td><?php echo esc_html($beta_labels[$batch_beta] ?? $batch_beta); ?></td>
                        <td><?php echo esc_html($fiscal_labels[$batch_fiscal] ?? $batch_fiscal); ?></td>
                        <td><?php echo esc_html($batch['created_at']); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="spf-panel">
        <h2><?php esc_html_e('Comprobantes individuales','sistema-para-farmacias'); ?></h2>
        <div class="spf-table-scroll spf-table-wide">
            <table class="widefat striped">
                <thead><tr><th><?php esc_html_e('Comprobante','sistema-para-farmacias'); ?></th><th><?php esc_html_e('Venta','sistema-para-farmacias'); ?></th><th><?php esc_html_e('Cliente','sistema-para-farmacias'); ?></th><th><?php esc_html_e('Total','sistema-para-farmacias'); ?></th><th><?php esc_html_e('Interno','sistema-para-farmacias'); ?></th><th><?php esc_html_e('Firma','sistema-para-farmacias'); ?></th><th><?php esc_html_e('Validación','sistema-para-farmacias'); ?></th><th><?php esc_html_e('Transporte','sistema-para-farmacias'); ?></th><th><?php esc_html_e('Beta','sistema-para-farmacias'); ?></th><th><?php esc_html_e('Fiscal','sistema-para-farmacias'); ?></th><th><?php esc_html_e('Estrategia','sistema-para-farmacias'); ?></th><th><?php esc_html_e('Acciones','sistema-para-farmacias'); ?></th></tr></thead>
                <tbody>
                <?php if (!$rows): ?><tr><td colspan="12"><?php esc_html_e('No hay comprobantes.','sistema-para-farmacias'); ?></td></tr><?php endif; ?>
                <?php foreach ((array)$rows as $row):
                    $internal=sanitize_key($row['internal_status']?:$row['sunat_status']);
                    $signature_status=sanitize_key($row['signature_status']?:'not_signed');
                    $validation_status=sanitize_key($row['validation_status']?:'not_validated');
                    $transport_status=sanitize_key($row['transport_status']?:'not_prepared');
                    $beta_status=sanitize_key($row['beta_status']?:'not_tested');
                    $fiscal=sanitize_key($row['fiscal_status']?:'not_sent');
                    $document_type=sanitize_key($row['document_type']);
                    $strategy=SPF_Fiscal_Transport_Manager::strategy_for_document($document_type);
                    $beta_supported=in_array($document_type,$beta_scope,true);
                    $beta_ready=$beta_enabled&&$beta_supported&&$signature_status==='verified'&&$validation_status==='valid_local'; ?>
                    <tr>
                        <td><strong><?php echo esc_html($row['serie'].'-'.$row['correlative']); ?></strong><br><small><?php echo esc_html($row['document_type']); ?></small></td>
                        <td><?php echo esc_html($row['sale_number']); ?><br><small><?php echo esc_html($row['sale_date']); ?></small></td>
                        <td><?php echo esc_html($row['business_name']?:$row['customer_name']?:'—'); ?><br><small><?php echo esc_html($row['customer_document_number']); ?></small></td>
                        <td><?php echo esc_html(SPF_Config::money($row['total'])); ?></td>
                        <td><?php echo esc_html($internal_labels[$internal]??$internal); ?></td>
                        <td><?php echo esc_html($signature_labels[$signature_status]??$signature_status); ?></td>
                        <td><?php echo esc_html($validation_labels[$validation_status]??$validation_status); ?></td>
                        <td><?php echo esc_html($transport_labels[$transport_status]??$transport_status); ?></td>
                        <td><?php echo esc_html($beta_labels[$beta_status]??$beta_status); ?></td>
                        <td><?php echo esc_html($fiscal_labels[$fiscal]??$fiscal); ?></td>
                        <td><code><?php echo esc_html($strategy); ?></code></td>
                        <td><div class="spf-form-actions">
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><?php wp_nonce_field('spf_prepare_fiscal_document'); ?><input type="hidden" name="action" value="spf_prepare_fiscal_document"><input type="hidden" name="document_id" value="<?php echo esc_attr($row['id']); ?>"><button class="button" <?php disabled(!$adapter); ?>><?php esc_html_e('Preflight','sistema-para-farmacias'); ?></button></form>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><?php wp_nonce_field('spf_fiscal_sign_local'); ?><input type="hidden" name="action" value="spf_fiscal_sign_local"><input type="hidden" name="document_id" value="<?php echo esc_attr($row['id']); ?>"><button class="button" <?php disabled(!$adapter||!$signer||!$provider); ?>><?php esc_html_e('Firmar','sistema-para-farmacias'); ?></button></form>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><?php wp_nonce_field('spf_fiscal_validate_local'); ?><input type="hidden" name="action" value="spf_fiscal_validate_local"><input type="hidden" name="document_id" value="<?php echo esc_attr($row['id']); ?>"><button class="button" <?php disabled(!$validator||(!$has_xsd&&!$has_xsl)); ?>><?php esc_html_e('Validar XSD/XSL','sistema-para-farmacias'); ?></button></form>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><?php wp_nonce_field('spf_fiscal_transport_dry_run'); ?><input type="hidden" name="action" value="spf_fiscal_transport_dry_run"><input type="hidden" name="document_id" value="<?php echo esc_attr($row['id']); ?>"><button class="button button-primary" <?php disabled(!$adapter||!$transport); ?>><?php esc_html_e('Dry-run','sistema-para-farmacias'); ?></button></form>
                            <?php if ($beta_supported): ?>
                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('<?php echo esc_js(__('Enviar este COMPROBANTE DE PRUEBA al servicio beta fiscal. No uses datos reales ni ejecutes pruebas masivas. ¿Continuar?','sistema-para-farmacias')); ?>');"><?php wp_nonce_field('spf_fiscal_beta_invoice'); ?><input type="hidden" name="action" value="spf_fiscal_beta_invoice"><input type="hidden" name="document_id" value="<?php echo esc_attr($row['id']); ?>"><button class="button" <?php disabled(!$beta_ready); ?>><?php echo esc_html(sprintf(__('Probar %s beta','sistema-para-farmacias'),$authority)); ?></button></form>
                            <?php elseif ($strategy === 'daily_summary'): ?>
                                <small><?php esc_html_e('Se agrupa en lote fiscal diario; envío remoto pendiente.','sistema-para-farmacias'); ?></small>
                            <?php endif; ?>
                        </div></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
