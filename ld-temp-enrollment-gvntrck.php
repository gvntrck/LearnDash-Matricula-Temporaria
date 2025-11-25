<?php
/**
 * Plugin Name: LearnDash Matrícula Temporária
 * Description: Sistema de matrícula temporária com desmatrícula automática para LearnDash
 * Version: 1.6.4
 * Author: Gvntrck
 * Author URI: https://github.com/gvntrck
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if (!defined('ABSPATH')) {
    exit;
}

class LearnDash_Temporary_Enrollment {
    
    private $table_name;
    
    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'ld_temp_enrollments';
        
        add_action('init', array($this, 'init'));
        add_action('admin_menu', array($this, 'add_admin_menu'), 20);
        add_action('ld_temp_check_expirations', array($this, 'check_expirations'));
        add_action('wp_ajax_ld_temp_enroll', array($this, 'ajax_enroll_user'));
        add_action('wp_ajax_ld_temp_unenroll', array($this, 'ajax_unenroll_user'));
        add_shortcode('ld_temp_enrollments_table', array($this, 'render_enrollments_table'));
        add_shortcode('ld_temp_enrollment_form', array($this, 'render_enrollment_form'));
    }
    
    /**
     * Inicialização do plugin
     */
    public function init() {
        $this->create_database_table();
    }
    
    /**
     * Adiciona menu no admin do WordPress
     */
    public function add_admin_menu() {
        // Verifica se LearnDash está ativo
        if (!defined('LEARNDASH_VERSION')) {
            return;
        }
        
        add_submenu_page(
            'learndash-lms',
            'Matrícula Temporária',
            'Matrícula Temporária',
            'manage_options',
            'ld-temp-enrollment',
            array($this, 'render_admin_page')
        );
    }
    
    /**
     * Renderiza página administrativa
     */
    public function render_admin_page() {
        if (!current_user_can('manage_options')) {
            wp_die('Você não tem permissão para acessar esta página.');
        }
        
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">
                <span class="dashicons dashicons-clock" style="font-size: 30px; margin-right: 10px;"></span>
                Matrícula Temporária - LearnDash
            </h1>
            <hr class="wp-header-end">
           
            <div style="background: #fff; padding: 20px; margin-top: 20px; border: 1px solid #ccd0d4; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
                <h2 style="margin-top: 0;">Matrículas Ativas</h2>
                <?php echo $this->render_enrollments_table(array('status' => 'active')); ?>
            </div>
            
            <div style="background: #fff; padding: 20px; margin-top: 20px; border: 1px solid #ccd0d4; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
                <h2 style="margin-top: 0;">Histórico de Matrículas Expiradas</h2>
                <?php echo $this->render_enrollments_table(array('status' => 'expired', 'show_actions' => 'false')); ?>
            </div>

             
            <div style="background: #fff; padding: 20px; margin-top: 20px; border: 1px solid #ccd0d4; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
                <h2 style="margin-top: 0;">Nova Matrícula</h2>
                <?php echo $this->render_enrollment_form(array()); ?>
            </div>
            
            <div style="background: #e7f5fe; padding: 15px; margin-top: 20px; border-left: 4px solid #0073aa;">
                <h3 style="margin-top: 0;"><span class="dashicons dashicons-info"></span> Informações</h3>
                <p><strong>Shortcodes disponíveis:</strong></p>
                <ul>
                    <li><code>[ld_temp_enrollments_table]</code> - Tabela de matrículas ativas</li>
                    <li><code>[ld_temp_enrollments_table status="expired"]</code> - Tabela de matrículas expiradas</li>
                    <li><code>[ld_temp_enrollment_form]</code> - Formulário de matrícula</li>
                </ul>
                <p><strong>WP-Cron Hook:</strong> <code>ld_temp_check_expirations</code></p>
                <p><em>Configure no plugin WP Crontrol para desmatrícula automática (recomendado: Hourly)</em></p>
            </div>
        </div>
        <?php
    }
    
    /**
     * Cria tabela customizada no banco de dados
     */
    private function create_database_table() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS {$this->table_name} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            user_id bigint(20) NOT NULL,
            course_id bigint(20) NOT NULL,
            expiration_date datetime NOT NULL,
            enrolled_date datetime DEFAULT CURRENT_TIMESTAMP,
            status varchar(20) DEFAULT 'active',
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY course_id (course_id),
            KEY expiration_date (expiration_date),
            KEY status (status),
            KEY user_course (user_id, course_id)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
    
    /**
     * Matricula usuário temporariamente em um curso
     * 
     * @param int $user_id ID do usuário
     * @param int $course_id ID do curso
     * @param int $duration_days Duração em dias
     * @return bool|int ID do registro ou false em caso de erro
     */
    public function enroll_user_temporarily($user_id, $course_id, $duration_days = 1) {
        global $wpdb;
        
        // Validação de parâmetros
        $user_id = intval($user_id);
        $course_id = intval($course_id);
        $duration_days = intval($duration_days);
        
        // Valida duration_days (1 a 365 dias)
        if ($duration_days < 1 || $duration_days > 365) {
            return array('error' => 'invalid_duration', 'message' => 'Duração deve ser entre 1 e 365 dias');
        }
        
        // Verifica se o usuário e curso existem
        if (!get_userdata($user_id) || get_post_type($course_id) !== 'sfwd-courses') {
            return array('error' => 'invalid_data', 'message' => 'Usuário ou curso inválido');
        }
        
        // Verifica se LearnDash está ativo
        if (!function_exists('ld_update_course_access')) {
            return array('error' => 'learndash_missing', 'message' => 'LearnDash não está ativo');
        }
        
        // Verifica se já existe matrícula ativa para este usuário neste curso
        $existing_active = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_name} 
            WHERE user_id = %d 
            AND course_id = %d 
            AND status = 'active'",
            $user_id,
            $course_id
        ));
        
        if ($existing_active) {
            return array(
                'error' => 'duplicate', 
                'message' => 'Usuário já possui matrícula ativa neste curso',
                'existing_id' => $existing_active->id,
                'existing_expiration' => $existing_active->expiration_date
            );
        }
        
        // Calcula data de expiração usando timezone do WordPress (Brasília GMT-3)
        $current_date = current_time('mysql');
        $expiration_date = date('Y-m-d H:i:s', strtotime("+{$duration_days} days", strtotime($current_date)));
        
        // Matricula no LearnDash
        ld_update_course_access($user_id, $course_id, false);
        
        // Registra na tabela customizada
        $inserted = $wpdb->insert(
            $this->table_name,
            array(
                'user_id' => $user_id,
                'course_id' => $course_id,
                'expiration_date' => $expiration_date,
                'enrolled_date' => $current_date,
                'status' => 'active'
            ),
            array('%d', '%d', '%s', '%s', '%s')
        );
        
        if ($inserted) {
            return $wpdb->insert_id;
        }
        
        return false;
    }
    
    /**
     * Desmatricula usuário de um curso
     * 
     * @param int $enrollment_id ID do registro de matrícula
     * @return bool
     */
    public function unenroll_user($enrollment_id) {
        global $wpdb;
        
        $enrollment = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE id = %d",
            $enrollment_id
        ));
        
        if (!$enrollment) {
            return false;
        }
        
        // Remove acesso ao curso no LearnDash
        ld_update_course_access($enrollment->user_id, $enrollment->course_id, true);
        
        // Atualiza status
        $wpdb->update(
            $this->table_name,
            array('status' => 'expired'),
            array('id' => $enrollment_id),
            array('%s'),
            array('%d')
        );
        
        return true;
    }
    
    /**
     * Verifica e processa matrículas expiradas (WP-Cron)
     * Hook: ld_temp_check_expirations
     */
    public function check_expirations() {
        global $wpdb;
        
        $current_time = current_time('mysql');
        
        $expired_enrollments = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table_name} 
            WHERE status = 'active' 
            AND expiration_date <= %s",
            $current_time
        ));
        
        $count = 0;
        foreach ($expired_enrollments as $enrollment) {
            if ($this->unenroll_user($enrollment->id)) {
                $count++;
            }
        }
        
        // Log para debug (opcional)
        error_log("LearnDash Temp Enrollment: {$count} matrículas expiradas processadas.");
        
        return $count;
    }
    
    /**
     * Handler AJAX para matricular usuário(s)
     */
    public function ajax_enroll_user() {
        check_ajax_referer('ld_temp_enroll_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permissão negada'));
        }
        
        $user_emails = isset($_POST['user_emails']) ? sanitize_textarea_field($_POST['user_emails']) : '';
        $course_id = intval($_POST['course_id']);
        $duration_days = intval($_POST['duration_days']);
        
        // Validação server-side
        if ($duration_days < 1 || $duration_days > 365) {
            wp_send_json_error(array('message' => 'Duração deve ser entre 1 e 365 dias'));
        }
        
        if (!$course_id || get_post_type($course_id) !== 'sfwd-courses') {
            wp_send_json_error(array('message' => 'Curso inválido'));
        }
        
        // Processa emails (um por linha)
        $emails = array_filter(array_map('trim', explode("\n", $user_emails)));
        
        if (empty($emails)) {
            wp_send_json_error(array('message' => 'Nenhum email fornecido'));
        }
        
        $success_count = 0;
        $error_count = 0;
        $errors = array();
        
        foreach ($emails as $email) {
            // Valida email
            if (!is_email($email)) {
                $errors[] = "Email inválido: " . esc_html($email);
                $error_count++;
                continue;
            }
            
            // Busca usuário por email
            $user = get_user_by('email', $email);
            
            if (!$user) {
                $errors[] = "Usuário não encontrado: " . esc_html($email);
                $error_count++;
                continue;
            }
            
            // Matricula o usuário
            $result = $this->enroll_user_temporarily($user->ID, $course_id, $duration_days);
            
            // Verifica se foi sucesso (número inteiro positivo)
            if (is_int($result) && $result > 0) {
                $success_count++;
            } else {
                // Trata erros retornados como array
                if (is_array($result) && isset($result['message'])) {
                    $errors[] = esc_html($email) . ": " . esc_html($result['message']);
                } else {
                    $errors[] = "Erro ao matricular: " . esc_html($email);
                }
                $error_count++;
            }
        }
        
        // Prepara mensagem de resposta
        $message = "";
        if ($success_count > 0) {
            $message .= "{$success_count} usuário(s) matriculado(s) com sucesso!";
        }
        if ($error_count > 0) {
            $message .= " {$error_count} erro(s) encontrado(s).";
        }
        
        wp_send_json_success(array(
            'message' => $message,
            'success_count' => $success_count,
            'error_count' => $error_count,
            'errors' => $errors
        ));
    }
    
    /**
     * Handler AJAX para desmatricular usuário
     */
    public function ajax_unenroll_user() {
        check_ajax_referer('ld_temp_unenroll_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permissão negada'));
        }
        
        $enrollment_id = intval($_POST['enrollment_id']);
        $result = $this->unenroll_user($enrollment_id);
        
        if ($result) {
            wp_send_json_success(array('message' => 'Usuário desmatriculado com sucesso!'));
        } else {
            wp_send_json_error(array('message' => 'Erro ao desmatricular usuário'));
        }
    }
    
    /**
     * Renderiza formulário de matrícula temporária
     * 
     * @param array $atts Atributos do shortcode
     * @return string HTML do formulário
     */
    public function render_enrollment_form($atts) {
        if (!current_user_can('manage_options')) {
            return '<div class="alert alert-danger">Você não tem permissão para acessar este formulário.</div>';
        }
        
        $courses = get_posts(array(
            'post_type' => 'sfwd-courses',
            'posts_per_page' => -1,
            'orderby' => 'title',
            'order' => 'ASC'
        ));
        
        ob_start();
        ?>
        <div class="ld-temp-enrollment-form-wrapper">
            <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/css/bootstrap.min.css" rel="stylesheet">
            
            <div class="">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0"><i class="bi bi-person-plus"></i> Matrícula Temporária em Lote</h5>
                </div>
                <div class="card-body">
                    <div class="alert alert-info">
                        <strong><i class="bi bi-info-circle"></i> Dica:</strong> Insira um email por linha para matricular múltiplos usuários de uma vez.
                    </div>
                    
                    <form id="ld-temp-enrollment-form">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="user_emails" class="form-label">Emails dos Usuários *</label>
                                <textarea name="user_emails" id="user_emails" class="form-control" rows="8" 
                                          placeholder="usuario1@exemplo.com&#10;usuario2@exemplo.com&#10;usuario3@exemplo.com" 
                                          required></textarea>
                                <div class="form-text">
                                    <i class="bi bi-arrow-return-left"></i> Um email por linha. Emails inválidos serão ignorados.
                                </div>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label for="course_id" class="form-label">Curso *</label>
                                <select name="course_id" id="course_id" class="form-select" required>
                                    <option value="">Selecione um curso</option>
                                    <?php foreach ($courses as $course): ?>
                                        <option value="<?php echo esc_attr($course->ID); ?>">
                                            <?php echo esc_html($course->post_title); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label for="expiration_date" class="form-label">Data de Expiração</label>
                                <input type="text" name="expiration_date" id="expiration_date" class="form-control" 
                                       placeholder="dd/mm/aaaa" maxlength="10">
                                <div class="form-text">Preencha a data ou use os dias abaixo</div>
                            </div>
                            
                            <div class="col-md-4 mb-3">
                                <label for="duration_days" class="form-label">Duração (dias) *</label>
                                <input type="number" name="duration_days" id="duration_days" class="form-control" 
                                       value="1" min="1" max="365" required>
                                <div class="form-text">Máximo: 365 dias (1 ano)</div>
                            </div>
                            
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Atalhos de Duração</label>
                                <div class="btn-group d-flex flex-wrap" role="group">
                                    <button type="button" class="btn btn-outline-secondary btn-sm ld-duration-btn" data-days="1">1 dia</button>
                                    <button type="button" class="btn btn-outline-secondary btn-sm ld-duration-btn" data-days="7">7 dias</button>
                                    <button type="button" class="btn btn-outline-secondary btn-sm ld-duration-btn" data-days="15">15 dias</button>
                                    <button type="button" class="btn btn-outline-secondary btn-sm ld-duration-btn" data-days="30">30 dias</button>
                                </div>
                            </div>
                        </div>
                        
                        <div class="d-grid gap-2">
                            <button type="submit" class="btn btn-primary btn-lg">
                                <i class="bi bi-check-circle"></i> Matricular Temporariamente
                            </button>
                        </div>
                    </form>
                    
                    <div id="ld-enrollment-message" class="mt-3" style="display:none;"></div>
                </div>
            </div>
            
            <script>
            jQuery(document).ready(function($) {
                // Máscara para campo de data dd/mm/aaaa
                $('#expiration_date').on('input', function(e) {
                    var value = $(this).val().replace(/\D/g, '');
                    var formatted = '';
                    
                    if (value.length > 0) {
                        formatted = value.substring(0, 2);
                    }
                    if (value.length > 2) {
                        formatted += '/' + value.substring(2, 4);
                    }
                    if (value.length > 4) {
                        formatted += '/' + value.substring(4, 8);
                    }
                    
                    $(this).val(formatted);
                    
                    // Calcula dias quando a data está completa
                    if (formatted.length === 10) {
                        calculateDaysFromDate(formatted);
                    }
                });
                
                // Função para calcular dias a partir da data
                function calculateDaysFromDate(dateStr) {
                    var parts = dateStr.split('/');
                    if (parts.length !== 3) return;
                    
                    var day = parseInt(parts[0], 10);
                    var month = parseInt(parts[1], 10) - 1; // Mês começa em 0
                    var year = parseInt(parts[2], 10);
                    
                    // Valida data
                    if (isNaN(day) || isNaN(month) || isNaN(year)) return;
                    if (day < 1 || day > 31 || month < 0 || month > 11 || year < 2020) return;
                    
                    var expirationDate = new Date(year, month, day, 23, 59, 59);
                    var today = new Date();
                    today.setHours(0, 0, 0, 0);
                    
                    var diffTime = expirationDate - today;
                    var diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24));
                    
                    if (diffDays >= 1 && diffDays <= 365) {
                        $('#duration_days').val(diffDays);
                    } else if (diffDays < 1) {
                        alert('A data de expiração deve ser futura!');
                        $('#expiration_date').val('');
                    } else if (diffDays > 365) {
                        alert('A data de expiração não pode ser maior que 365 dias!');
                        $('#expiration_date').val('');
                    }
                }
                
                // Função para calcular data a partir dos dias
                function calculateDateFromDays(days) {
                    var today = new Date();
                    var expirationDate = new Date(today.getTime() + (days * 24 * 60 * 60 * 1000));
                    
                    var day = String(expirationDate.getDate()).padStart(2, '0');
                    var month = String(expirationDate.getMonth() + 1).padStart(2, '0');
                    var year = expirationDate.getFullYear();
                    
                    return day + '/' + month + '/' + year;
                }
                
                // Atualiza data quando dias mudam
                $('#duration_days').on('change input', function() {
                    var days = parseInt($(this).val(), 10);
                    if (days >= 1 && days <= 365) {
                        $('#expiration_date').val(calculateDateFromDays(days));
                    }
                });
                
                // Botões de atalho de duração
                $('.ld-duration-btn').on('click', function() {
                    var days = $(this).data('days');
                    $('#duration_days').val(days);
                    $('#expiration_date').val(calculateDateFromDays(days));
                });
                
                // Inicializa com 1 dia
                $('#expiration_date').val(calculateDateFromDays(1));
                
                $('#ld-temp-enrollment-form').on('submit', function(e) {
                    e.preventDefault();
                    
                    var $form = $(this);
                    var $btn = $form.find('button[type="submit"]');
                    var $message = $('#ld-enrollment-message');
                    
                    $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> Processando...');
                    $message.hide();
                    
                    $.ajax({
                        url: '<?php echo admin_url('admin-ajax.php'); ?>',
                        type: 'POST',
                        data: {
                            action: 'ld_temp_enroll',
                            nonce: '<?php echo wp_create_nonce('ld_temp_enroll_nonce'); ?>',
                            user_emails: $('#user_emails').val(),
                            course_id: $('#course_id').val(),
                            duration_days: $('#duration_days').val()
                        },
                        success: function(response) {
                            if (response.success) {
                                var alertClass = response.data.error_count > 0 ? 'alert-warning' : 'alert-success';
                                var messageHtml = '<div class="alert ' + alertClass + '">' + response.data.message;
                                
                                if (response.data.errors && response.data.errors.length > 0) {
                                    messageHtml += '<hr><strong>Erros:</strong><ul class="mb-0">';
                                    response.data.errors.forEach(function(error) {
                                        messageHtml += '<li>' + error + '</li>';
                                    });
                                    messageHtml += '</ul>';
                                }
                                
                                messageHtml += '</div>';
                                $message.html(messageHtml).show();
                                
                                if (response.data.success_count > 0) {
                                    $form[0].reset();
                                    setTimeout(function() {
                                        location.reload();
                                    }, 3000);
                                }
                            } else {
                                $message.html('<div class="alert alert-danger">' + response.data.message + '</div>').show();
                            }
                        },
                        error: function() {
                            $message.html('<div class="alert alert-danger">Erro ao processar requisição.</div>').show();
                        },
                        complete: function() {
                            $btn.prop('disabled', false).html('<i class="bi bi-check-circle"></i> Matricular Temporariamente');
                        }
                    });
                });
            });
            </script>
            <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.min.js"></script>
        </div>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Renderiza tabela de matrículas temporárias
     * 
     * @param array $atts Atributos do shortcode
     * @return string HTML da tabela
     */
    public function render_enrollments_table($atts) {
        global $wpdb;
        
        $atts = shortcode_atts(array(
            'status' => 'active',
            'limit' => 100,
            'show_actions' => 'true'
        ), $atts);
        
        $enrollments = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table_name} 
            WHERE status = %s 
            ORDER BY expiration_date ASC 
            LIMIT %d",
            $atts['status'],
            $atts['limit']
        ));
        
        $show_actions = ($atts['show_actions'] === 'true' && current_user_can('manage_options'));
        
        ob_start();
        ?>
        <div class="ld-temp-enrollments-wrapper">
            <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/css/bootstrap.min.css" rel="stylesheet">
            <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
            <style>
                .ld-temp-enrollments-wrapper {
                    margin: 20px 0;
                }
                .status-badge {
                    padding: 4px 8px;
                    border-radius: 4px;
                    font-size: 12px;
                    font-weight: 600;
                }
                .status-active {
                    background-color:rgb(89, 224, 121);
                    color: #155724;
                }
                .status-expired {
                    background-color: #f8d7da;
                    color: #721c24;
                }
                .time-remaining {
                    font-size: 13px;
                    color: #666;
                }
                .action-buttons .btn {
                    padding: 4px 8px;
                    font-size: 12px;
                }
            </style>
            
            <div class="table-responsive">
                <table class="table table-striped table-hover">
                    <thead class="table-light">
                        <tr>
                            <th>Email</th>
                            <th>Nome Completo</th>
                            <th>Curso</th>
                            <th>Data de Matrícula</th>
                            <th>Data de Expiração</th>
                            <th>Tempo Restante</th>
                            <th>Status</th>
                            <?php if ($show_actions): ?>
                                <th class="text-center">Ações</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($enrollments)): ?>
                            <tr>
                                <td colspan="<?php echo $show_actions ? '8' : '7'; ?>" class="text-center py-4">
                                    Nenhuma matrícula temporária encontrada.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($enrollments as $enrollment): ?>
                                <?php
                                $user = get_userdata($enrollment->user_id);
                                $course = get_post($enrollment->course_id);
                                
                                if (!$user || !$course) {
                                    continue;
                                }
                                
                                $expiration_timestamp = strtotime($enrollment->expiration_date);
                                $current_timestamp = current_time('timestamp');
                                $time_diff = $expiration_timestamp - $current_timestamp;
                                
                                $time_remaining = $this->format_time_remaining($time_diff);
                                ?>
                                <tr id="enrollment-row-<?php echo esc_attr($enrollment->id); ?>">
                                    <td><?php echo esc_html($user->user_email); ?></td>
                                    <td><?php echo esc_html($user->display_name); ?></td>
                                    <td><?php echo esc_html($course->post_title); ?></td>
                                    <td><?php echo esc_html(date_i18n('d/m/Y H:i', strtotime($enrollment->enrolled_date))); ?></td>
                                    <td><?php echo esc_html(date_i18n('d/m/Y H:i', $expiration_timestamp)); ?></td>
                                    <td class="time-remaining"><?php echo esc_html($time_remaining); ?></td>
                                    <td>
                                        <span class="badge status-badge status-<?php echo esc_attr($enrollment->status); ?>">
                                            <?php echo esc_html(ucfirst($enrollment->status)); ?>
                                        </span>
                                    </td>
                                    <?php if ($show_actions): ?>
                                        <td class="text-center action-buttons">
                                            <?php if ($enrollment->status === 'active'): ?>
                                                <button class="btn btn-danger btn-sm ld-unenroll-btn" 
                                                        data-enrollment-id="<?php echo esc_attr($enrollment->id); ?>"
                                                        title="Desmatricular agora">
                                                    <i class="bi bi-x-circle"></i> Desmatricular
                                                </button>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            
            <?php if ($show_actions): ?>
            <script>
            jQuery(document).ready(function($) {
                $('.ld-unenroll-btn').on('click', function() {
                    if (!confirm('Tem certeza que deseja desmatricular este usuário agora?')) {
                        return;
                    }
                    
                    var $btn = $(this);
                    var enrollmentId = $btn.data('enrollment-id');
                    var $row = $('#enrollment-row-' + enrollmentId);
                    
                    $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span>');
                    
                    $.ajax({
                        url: '<?php echo admin_url('admin-ajax.php'); ?>',
                        type: 'POST',
                        data: {
                            action: 'ld_temp_unenroll',
                            nonce: '<?php echo wp_create_nonce('ld_temp_unenroll_nonce'); ?>',
                            enrollment_id: enrollmentId
                        },
                        success: function(response) {
                            if (response.success) {
                                $row.fadeOut(400, function() {
                                    $(this).remove();
                                });
                                alert(response.data.message);
                            } else {
                                alert(response.data.message);
                                $btn.prop('disabled', false).html('<i class="bi bi-x-circle"></i> Desmatricular');
                            }
                        },
                        error: function() {
                            alert('Erro ao processar requisição.');
                            $btn.prop('disabled', false).html('<i class="bi bi-x-circle"></i> Desmatricular');
                        }
                    });
                });
            });
            </script>
            <?php endif; ?>
            
            <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.min.js"></script>
        </div>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Formata tempo restante de forma legível
     * 
     * @param int $seconds Segundos restantes
     * @return string Tempo formatado
     */
    private function format_time_remaining($seconds) {
        if ($seconds <= 0) {
            return 'Expirado';
        }
        
        $days = floor($seconds / 86400);
        $hours = floor(($seconds % 86400) / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        
        $parts = array();
        
        if ($days > 0) {
            $parts[] = $days . ' dia' . ($days > 1 ? 's' : '');
        }
        if ($hours > 0) {
            $parts[] = $hours . ' hora' . ($hours > 1 ? 's' : '');
        }
        if ($minutes > 0 && $days === 0) {
            $parts[] = $minutes . ' minuto' . ($minutes > 1 ? 's' : '');
        }
        
        return !empty($parts) ? implode(', ', $parts) : 'Menos de 1 minuto';
    }
    
    /**
     * Obtém todas as matrículas ativas de um usuário
     * 
     * @param int $user_id ID do usuário
     * @return array
     */
    public function get_user_enrollments($user_id) {
        global $wpdb;
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table_name} 
            WHERE user_id = %d 
            AND status = 'active' 
            ORDER BY expiration_date ASC",
            $user_id
        ));
    }
    
    /**
     * Obtém todas as matrículas de um curso
     * 
     * @param int $course_id ID do curso
     * @return array
     */
    public function get_course_enrollments($course_id) {
        global $wpdb;
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table_name} 
            WHERE course_id = %d 
            AND status = 'active' 
            ORDER BY expiration_date ASC",
            $course_id
        ));
    }
}

// Inicializa o plugin
$GLOBALS['ld_temp_enrollment'] = new LearnDash_Temporary_Enrollment();

/**
 * Funções auxiliares para uso em outros lugares
 */

/**
 * Matricula usuário temporariamente
 * 
 * @param int $user_id ID do usuário
 * @param int $course_id ID do curso
 * @param int $duration_days Duração em dias (padrão: 1)
 * @return bool|int
 */
function ld_enroll_user_temporarily($user_id, $course_id, $duration_days = 1) {
    global $ld_temp_enrollment;
    return $ld_temp_enrollment->enroll_user_temporarily($user_id, $course_id, $duration_days);
}

/**
 * Desmatricula usuário
 * 
 * @param int $enrollment_id ID do registro de matrícula
 * @return bool
 */
function ld_unenroll_user_temporarily($enrollment_id) {
    global $ld_temp_enrollment;
    return $ld_temp_enrollment->unenroll_user($enrollment_id);
}

/**
 * Obtém matrículas de um usuário
 * 
 * @param int $user_id ID do usuário
 * @return array
 */
function ld_get_user_temp_enrollments($user_id) {
    global $ld_temp_enrollment;
    return $ld_temp_enrollment->get_user_enrollments($user_id);
}

/**
 * Exemplo de uso:
 * 
 * // Matricular usuário por 7 dias
 * ld_enroll_user_temporarily(1, 123, 7);
 * 
 * // Matricular por 30 dias
 * ld_enroll_user_temporarily(1, 123, 30);
 * 
 * // Exibir formulário de matrícula
 * [ld_temp_enrollment_form]
 * 
 * // Exibir tabela em uma página
 * [ld_temp_enrollments_table]
 * 
 * // Exibir apenas matrículas expiradas
 * [ld_temp_enrollments_table status="expired"]
 */
