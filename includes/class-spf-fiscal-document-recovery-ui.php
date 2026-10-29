<?php
if (!defined('ABSPATH')) { exit; }

final class SPF_Fiscal_Document_Recovery_UI {
    public function hooks() {
        add_action('admin_notices', [$this, 'render']);
    }

    public function render() {
        if (!$this->is_fiscal_screen()) { return; }
        if (!current_user_can(SPF_Roles::INVOICES) && !current_user_can('manage_options')) { return; }

        $candidates = SPF_Fiscal_Document_Remote_Event_Repository::recovery_candidates(20);
        if (!$candidates) { return; }
        $labels = SPF_Fiscal_Transport_Manager::beta_statuses();
        ?>
        <div class="notice notice-warning spf-fiscal-recovery-notice">
            <h2><?php esc_html_e('Recuperación fiscal beta pendiente', 'sistema-para-farmacias'); ?></h2>
            <p><?php esc_html_e('Hay respuestas beta cuya CDR ya está archivada y verificada en el ledger, pero la proyección local del comprobante no coincide. Recuperar solo reconstruye el estado local desde evidencia existente; no realiza un nuevo envío.', 'sistema-para-farmacias'); ?></p>
            <div style="overflow:auto;max-width:100%;margin:10px 0 14px">
                <table class="widefat striped" style="min-width:760px">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Comprobante', 'sistema-para-farmacias'); ?></th>
                            <th><?php esc_html_e('Estado archivado', 'sistema-para-farmacias'); ?></th>
                            <th><?php esc_html_e('Estado local', 'sistema-para-farmacias'); ?></th>
                            <th><?php esc_html_e('Evidencia', 'sistema-para-farmacias'); ?></th>
                            <th><?php esc_html_e('Archivado', 'sistema-para-farmacias'); ?></th>
                            <th><?php esc_html_e('Acción', 'sistema-para-farmacias'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($candidates as $candidate):
                        $document_id = absint($candidate['document_id'] ?? 0);
                        $archived_status = sanitize_key((string)($candidate['beta_status'] ?? ''));
                        $projected_status = sanitize_key((string)($candidate['projected_beta_status'] ?? ''));
                        $evidence = sanitize_text_field((string)($candidate['evidence_sha256'] ?? ''));
                        $reference = sanitize_text_field((string)($candidate['reference'] ?? ('#'.$document_id)));
                        ?>
                        <tr>
                            <td><strong><?php echo esc_html($reference); ?></strong><br><small>#<?php echo esc_html((string)$document_id); ?></small></td>
                            <td><?php echo esc_html($labels[$archived_status] ?? $archived_status); ?></td>
                            <td><?php echo esc_html($labels[$projected_status] ?? ($projected_status ?: __('Sin proyección', 'sistema-para-farmacias'))); ?></td>
                            <td><code><?php echo esc_html($evidence !== '' ? substr($evidence, 0, 16).'…' : '—'); ?></code><br><small><?php echo esc_html(sanitize_text_field((string)($candidate['remote_code'] ?? ''))); ?></small></td>
                            <td><?php echo esc_html(sanitize_text_field((string)($candidate['created_at'] ?? ''))); ?></td>
                            <td>
                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('<?php echo esc_js(__('Reconstruir la proyección local desde la evidencia archivada. No se realizará ningún envío remoto. ¿Continuar?', 'sistema-para-farmacias')); ?>');">
                                    <?php wp_nonce_field('spf_fiscal_beta_invoice_recover'); ?>
                                    <input type="hidden" name="action" value="spf_fiscal_beta_invoice_recover">
                                    <input type="hidden" name="document_id" value="<?php echo esc_attr((string)$document_id); ?>">
                                    <button type="submit" class="button button-primary"><?php esc_html_e('Recuperar desde ledger', 'sistema-para-farmacias'); ?></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p class="description"><?php esc_html_e('La recuperación server-side vuelve a validar que la firma, certificado, validación y fingerprint actuales coinciden con la evidencia archivada antes de modificar la proyección local.', 'sistema-para-farmacias'); ?></p>
        </div>
        <?php
    }

    private function is_fiscal_screen() {
        if (!is_admin()) { return false; }
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        return $page === 'spf-fiscal';
    }
}
