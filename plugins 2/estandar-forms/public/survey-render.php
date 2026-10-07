<?php
if ( ! defined('ABSPATH') ) exit;

if ( ! isset( $form_id ) || ! isset( $form ) || ! is_object( $form ) ) {
    return;
}

// MS-5 (ADR 0034): render conversacional por PERFIL. Survey → survey_config
// (identification_mode/hero_*/completion_*); experiencias genéricas (p. ej.
// lead_qualification) → conversation_config (identification/flow/scoring/
// completion_strategy). El runtime (DOM esvy-* + estandar-survey.js + AJAX) es
// compartido; la diferencia es configuración.
$raw_profile = class_exists( 'Estandar_Conversation_Engine' )
    ? Estandar_Conversation_Engine::config_for_form( $form )
    : (array) ( json_decode( (string) ( $form->survey_config ?? '' ), true ) ?: [] );

$survey_config = is_array( $raw_profile ) ? $raw_profile : [];

$engine_cfg = class_exists( 'Estandar_Conversation_Engine' )
    ? Estandar_Conversation_Engine::normalize_config( $survey_config )
    : [];

$id_mode            = sanitize_key( (string) ( $survey_config['identification_mode'] ?? ( $engine_cfg['identification'] ?? 'anonymous' ) ) );
$hero_title         = sanitize_text_field( (string) ( $survey_config['hero_title'] ?? '' ) );
$hero_subtitle      = sanitize_text_field( (string) ( $survey_config['hero_subtitle'] ?? '' ) );
$hero_cta           = sanitize_text_field( (string) ( $survey_config['hero_cta'] ?? 'Comenzar' ) );
$hero_image_url     = esc_url( (string) ( $survey_config['hero_image_url'] ?? '' ) );
$completion_title   = sanitize_text_field( (string) ( $survey_config['completion_title'] ?? '¡Gracias!' ) );
$completion_message = sanitize_text_field( (string) ( $survey_config['completion_message'] ?? 'Tu respuesta fue registrada exitosamente.' ) );

$fields = function_exists( 'estandar_forms_decode_fields' )
    ? estandar_forms_decode_fields( (string) $form->fields_json )
    : json_decode( (string) $form->fields_json, true );

if ( ! is_array( $fields ) ) {
    $fields = [];
}

$survey_questions = [];
foreach ( $fields as $field ) {
    if ( ! is_array( $field ) || empty( $field['type'] ) || empty( $field['name'] ) ) {
        continue;
    }
    $survey_questions[] = $field;
}

$total_questions = count( $survey_questions );
if ( $total_questions === 0 ) {
    return;
}

if ( ! isset( $form_instance_id ) || $form_instance_id === '' ) {
    static $survey_instance = 0;
    $survey_instance++;
    $form_instance_id = 'survey-' . (int) $form_id . '-' . $survey_instance;
}
$form_instance_id = sanitize_html_class( $form_instance_id );

$show_hero = $hero_title !== '' || $hero_subtitle !== '';
$show_identification = $id_mode !== 'anonymous';

$ajax_url = admin_url( 'admin-ajax.php' );
$nonce    = wp_create_nonce( 'estandar_survey_nonce' );

$survey_css_file = ESTANDAR_FORMS_PATH . 'assets/css/estandar-survey.css';
$survey_js_file  = ESTANDAR_FORMS_PATH . 'assets/js/estandar-survey.js';

wp_enqueue_style(
    'estandar-survey-public',
    ESTANDAR_FORMS_URL . 'assets/css/estandar-survey.css',
    [],
    file_exists( $survey_css_file ) ? (string) filemtime( $survey_css_file ) : ESTANDAR_FORMS_VERSION
);

wp_enqueue_script(
    'estandar-survey-public',
    ESTANDAR_FORMS_URL . 'assets/js/estandar-survey.js',
    [],
    file_exists( $survey_js_file ) ? (string) filemtime( $survey_js_file ) : ESTANDAR_FORMS_VERSION,
    true
);

$survey_flow_map = [];
if ( class_exists( 'Estandar_Conversation_Engine' ) ) {
    $survey_flow_map = Estandar_Conversation_Engine::flow_map(
        $engine_cfg,
        $survey_questions
    );
}

wp_localize_script( 'estandar-survey-public', 'EstandarSurvey', [
    'ajaxUrl'         => $ajax_url,
    'nonce'           => $nonce,
    'formId'          => $form_id,
    'totalQuestions'  => $total_questions,
    'idMode'          => $id_mode,
    'instanceId'      => $form_instance_id,
    'nextMap'         => $survey_flow_map,
] );

?>
<div class="esvy-wrap" id="<?php echo esc_attr( $form_instance_id ); ?>"
     data-form-id="<?php echo esc_attr( $form_id ); ?>"
     data-total="<?php echo esc_attr( $total_questions ); ?>"
     data-id-mode="<?php echo esc_attr( $id_mode ); ?>">

    <?php if ( $hero_image_url !== '' ) : ?>
    <div class="esvy-bg" style="background-image:url('<?php echo esc_attr( $hero_image_url ); ?>')"></div>
    <?php endif; ?>

    <!-- HERO SCREEN -->
    <?php if ( $show_hero ) : ?>
    <div class="esvy-screen esvy-screen--hero esvy-screen--active" data-screen="hero">
        <div class="esvy-screen-inner">
            <?php if ( $hero_title !== '' ) : ?>
            <h2 class="esvy-hero-title"><?php echo esc_html( $hero_title ); ?></h2>
            <?php endif; ?>
            <?php if ( $hero_subtitle !== '' ) : ?>
            <p class="esvy-hero-subtitle"><?php echo esc_html( $hero_subtitle ); ?></p>
            <?php endif; ?>
            <button class="esvy-btn esvy-btn--primary esvy-hero-cta" data-action="start">
                <?php echo esc_html( $hero_cta ); ?>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg>
            </button>
        </div>
    </div>
    <?php endif; ?>

    <!-- IDENTIFICATION SCREEN -->
    <?php if ( $show_identification ) : ?>
    <div class="esvy-screen esvy-screen--identification<?php echo $show_hero ? '' : ' esvy-screen--active'; ?>" data-screen="identification">
        <div class="esvy-screen-inner">
            <p class="esvy-screen-label esvy-screen-label--hint">Antes de comenzar</p>
            <h3 class="esvy-question-title">¿Cómo podemos identificarte?</h3>
            <?php if ( $id_mode === 'optional' ) : ?>
            <p class="esvy-optional-hint">Este paso es opcional. Puedes saltarlo.</p>
            <?php endif; ?>
            <div class="esvy-identification-fields">
                <div class="esvy-field-group">
                    <label class="esvy-label" for="<?php echo esc_attr($form_instance_id); ?>-respondent-name">Nombre</label>
                    <input class="esvy-input" type="text" id="<?php echo esc_attr($form_instance_id); ?>-respondent-name"
                           name="respondent_name" placeholder="Tu nombre" autocomplete="name" />
                </div>
                <div class="esvy-field-group">
                    <label class="esvy-label" for="<?php echo esc_attr($form_instance_id); ?>-respondent-email">Email</label>
                    <input class="esvy-input" type="email" id="<?php echo esc_attr($form_instance_id); ?>-respondent-email"
                           name="respondent_email" placeholder="correo@ejemplo.com" autocomplete="email" />
                </div>
                <div class="esvy-field-group">
                    <label class="esvy-label" for="<?php echo esc_attr($form_instance_id); ?>-respondent-phone">Teléfono</label>
                    <input class="esvy-input" type="tel" id="<?php echo esc_attr($form_instance_id); ?>-respondent-phone"
                           name="respondent_phone" placeholder="+56 9 1234 5678" autocomplete="tel" />
                </div>
            </div>
            <div class="esvy-nav">
                <?php if ( $id_mode === 'optional' ) : ?>
                <button class="esvy-btn esvy-btn--ghost" data-action="skip-identification">Saltar</button>
                <?php endif; ?>
                <button class="esvy-btn esvy-btn--primary" data-action="submit-identification">
                    Continuar
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg>
                </button>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- QUESTION SCREENS -->
    <?php foreach ( $survey_questions as $qi => $field ) :
        $f_name     = sanitize_key( (string) $field['name'] );
        $f_type     = sanitize_key( (string) $field['type'] );
        $f_label    = sanitize_text_field( (string) ( $field['label'] ?? '' ) );
        $f_hint     = sanitize_text_field( (string) ( $field['hint'] ?? '' ) );
        $f_required = ! empty( $field['required'] );
        $f_options  = is_array( $field['options'] ?? null ) ? $field['options'] : [];
        $f_max      = isset( $field['max'] ) ? (int) $field['max'] : 5;
        $screen_num = $qi + 1;
        $is_first   = ! $show_hero && ! $show_identification && $qi === 0;
    ?>
    <div class="esvy-screen esvy-screen--question<?php echo $is_first ? ' esvy-screen--active' : ''; ?>"
         data-screen="question"
         data-question-index="<?php echo esc_attr( $qi ); ?>"
         data-question-name="<?php echo esc_attr( $f_name ); ?>"
         data-question-type="<?php echo esc_attr( $f_type ); ?>"
         data-question-label="<?php echo esc_attr( $f_label ); ?>"
         data-required="<?php echo $f_required ? '1' : '0'; ?>">

        <div class="esvy-screen-inner">
            <p class="esvy-screen-label">
                <span class="esvy-q-num"><?php echo esc_html( $screen_num ); ?></span>
                <span class="esvy-q-sep">→</span>
            </p>

            <h3 class="esvy-question-title"><?php echo esc_html( $f_label ); ?></h3>

            <?php if ( $f_hint !== '' ) : ?>
            <p class="esvy-question-hint"><?php echo esc_html( $f_hint ); ?></p>
            <?php endif; ?>

            <div class="esvy-question-body">

                <?php if ( $f_type === 'nps' ) : ?>
                <div class="esvy-nps" data-name="<?php echo esc_attr($f_name); ?>">
                    <div class="esvy-nps-scale">
                        <?php for ( $n = 0; $n <= 7; $n++ ) :
                            $hue = round( $n / 7 * 120 );
                        ?>
                        <button class="esvy-nps-btn" data-value="<?php echo $n; ?>" type="button"
                                style="--esvy-nps-h:<?php echo (int) $hue; ?>"><?php echo $n; ?></button>
                        <?php endfor; ?>
                    </div>
                    <div class="esvy-nps-labels">
                        <span>Nada probable</span>
                        <span>Muy probable</span>
                    </div>
                </div>

                <?php elseif ( $f_type === 'rating' ) : ?>
                <div class="esvy-rating" data-name="<?php echo esc_attr($f_name); ?>" data-max="<?php echo esc_attr($f_max); ?>">
                    <?php for ( $s = 1; $s <= $f_max; $s++ ) : ?>
                    <button class="esvy-star-btn" data-value="<?php echo $s; ?>" type="button" aria-label="<?php echo $s; ?> estrella<?php echo $s > 1 ? 's' : ''; ?>">
                        <svg viewBox="0 0 24 24"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>
                    </button>
                    <?php endfor; ?>
                </div>

                <?php elseif ( $f_type === 'matrix' ) :
                    $f_rows     = is_array( $field['rows'] ?? null ) ? $field['rows'] : [];
                    $f_scale    = (int) ( $field['scale'] ?? 7 );
                    $f_lbl_min  = sanitize_text_field( (string) ( $field['label_min'] ?? '' ) );
                    $f_lbl_max  = sanitize_text_field( (string) ( $field['label_max'] ?? '' ) );
                    $f_col_lbls = is_array( $field['column_labels'] ?? null ) ? $field['column_labels'] : [];
                ?>
                <div class="esvy-matrix" data-name="<?php echo esc_attr($f_name); ?>" data-required="<?php echo $f_required ? '1' : '0'; ?>" data-scale="<?php echo esc_attr($f_scale); ?>">
                    <div class="esvy-matrix-table-wrap">
                        <table class="esvy-matrix-table">
                            <thead>
                                <tr>
                                    <th class="esvy-matrix-row-header"></th>
                                    <?php for ( $s = 1; $s <= $f_scale; $s++ ) :
                                        $hue = $f_scale > 1 ? round( ( $s - 1 ) / ( $f_scale - 1 ) * 120 ) : 60;
                                    ?>
                                    <th class="esvy-matrix-col-header">
                                        <?php echo $s; ?>
                                        <?php if ( ! empty( $f_col_lbls[ $s ] ) ) : ?>
                                        <div class="esvy-matrix-col-lbl-text"><?php echo esc_html( $f_col_lbls[ $s ] ); ?></div>
                                        <?php endif; ?>
                                    </th>
                                    <?php endfor; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ( $f_rows as $ri => $row ) :
                                    $row_id    = sanitize_key( (string) ( $row['id'] ?? 'row_' . $ri ) );
                                    $row_label = sanitize_text_field( (string) ( $row['label'] ?? '' ) );
                                ?>
                                <tr class="esvy-matrix-row" data-row-id="<?php echo esc_attr($row_id); ?>">
                                    <td class="esvy-matrix-row-label"><?php echo esc_html( $row_label ); ?></td>
                                    <?php for ( $s = 1; $s <= $f_scale; $s++ ) :
                                        $hue = $f_scale > 1 ? round( ( $s - 1 ) / ( $f_scale - 1 ) * 120 ) : 60;
                                    ?>
                                    <td class="esvy-matrix-cell">
                                        <button class="esvy-matrix-btn" data-row-id="<?php echo esc_attr($row_id); ?>" data-value="<?php echo $s; ?>" type="button"
                                                style="--esvy-matrix-h:<?php echo (int) $hue; ?>"><?php echo $s; ?></button>
                                    </td>
                                    <?php endfor; ?>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php if ( $f_lbl_min !== '' || $f_lbl_max !== '' ) : ?>
                    <div class="esvy-matrix-labels">
                        <span><?php echo esc_html( $f_lbl_min ); ?></span>
                        <span><?php echo esc_html( $f_lbl_max ); ?></span>
                    </div>
                    <?php endif; ?>
                    <!-- Mobile: stacked cards -->
                    <div class="esvy-matrix-mobile">
                        <?php foreach ( $f_rows as $ri => $row ) :
                            $row_id    = sanitize_key( (string) ( $row['id'] ?? 'row_' . $ri ) );
                            $row_label = sanitize_text_field( (string) ( $row['label'] ?? '' ) );
                        ?>
                        <div class="esvy-matrix-mobile-row" data-row-id="<?php echo esc_attr($row_id); ?>">
                            <div class="esvy-matrix-mobile-label"><?php echo esc_html( $row_label ); ?></div>
                            <div class="esvy-matrix-mobile-btns">
                                <?php for ( $s = 1; $s <= $f_scale; $s++ ) :
                                    $hue = $f_scale > 1 ? round( ( $s - 1 ) / ( $f_scale - 1 ) * 120 ) : 60;
                                ?>
                                <button class="esvy-matrix-btn esvy-matrix-btn--mobile" data-row-id="<?php echo esc_attr($row_id); ?>" data-value="<?php echo $s; ?>" type="button"
                                        style="--esvy-matrix-h:<?php echo (int) $hue; ?>"><?php echo $s; ?></button>
                                <?php endfor; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        <?php if ( $f_lbl_min !== '' || $f_lbl_max !== '' ) : ?>
                        <div class="esvy-matrix-labels">
                            <span><?php echo esc_html( $f_lbl_min ); ?></span>
                            <span><?php echo esc_html( $f_lbl_max ); ?></span>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <?php elseif ( $f_type === 'emoji' ) : ?>
                <div class="esvy-emoji" data-name="<?php echo esc_attr($f_name); ?>">
                    <?php foreach ( $f_options as $opt ) :
                        if ( ! is_array( $opt ) ) continue;
                        $opt_value = sanitize_text_field( (string) ( $opt['value'] ?? '' ) );
                        $opt_label = sanitize_text_field( (string) ( $opt['label'] ?? '' ) );
                        $opt_emoji = sanitize_text_field( (string) ( $opt['emoji'] ?? '⭐' ) );
                    ?>
                    <button class="esvy-emoji-btn" data-value="<?php echo esc_attr($opt_value); ?>"
                            data-label="<?php echo esc_attr($opt_label); ?>" type="button">
                        <span class="esvy-emoji-icon"><?php echo esc_html($opt_emoji); ?></span>
                        <span class="esvy-emoji-label"><?php echo esc_html($opt_label); ?></span>
                    </button>
                    <?php endforeach; ?>
                </div>

                <?php elseif ( $f_type === 'cards' ) : ?>
                <div class="esvy-cards" data-name="<?php echo esc_attr($f_name); ?>">
                    <?php foreach ( $f_options as $opt ) :
                        if ( ! is_array( $opt ) ) continue;
                        $opt_value = sanitize_text_field( (string) ( $opt['value'] ?? '' ) );
                        $opt_label = sanitize_text_field( (string) ( $opt['label'] ?? '' ) );
                        $opt_desc  = sanitize_text_field( (string) ( $opt['description'] ?? '' ) );
                        $opt_icon  = sanitize_text_field( (string) ( $opt['icon'] ?? '' ) );
                    ?>
                    <button class="esvy-card" data-value="<?php echo esc_attr($opt_value); ?>"
                            data-label="<?php echo esc_attr($opt_label); ?>" type="button">
                        <?php if ( $opt_icon !== '' ) : ?>
                        <span class="esvy-card-icon"><?php echo esc_html($opt_icon); ?></span>
                        <?php endif; ?>
                        <span class="esvy-card-label"><?php echo esc_html($opt_label); ?></span>
                        <?php if ( $opt_desc !== '' ) : ?>
                        <span class="esvy-card-desc"><?php echo esc_html($opt_desc); ?></span>
                        <?php endif; ?>
                    </button>
                    <?php endforeach; ?>
                </div>

                <?php elseif ( $f_type === 'select' ) : ?>
                <div class="esvy-select-wrap" data-name="<?php echo esc_attr($f_name); ?>">
                    <select class="esvy-select" name="<?php echo esc_attr($f_name); ?>">
                        <option value="">— Selecciona una opción —</option>
                        <?php foreach ( $f_options as $opt ) :
                            if ( ! is_array( $opt ) ) continue;
                            $opt_value = sanitize_text_field( (string) ( $opt['value'] ?? '' ) );
                            $opt_label = sanitize_text_field( (string) ( $opt['label'] ?? $opt_value ) );
                        ?>
                        <option value="<?php echo esc_attr($opt_value); ?>"><?php echo esc_html($opt_label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <?php elseif ( $f_type === 'radio' ) : ?>
                <div class="esvy-radio-group" data-name="<?php echo esc_attr($f_name); ?>">
                    <?php foreach ( $f_options as $opt ) :
                        if ( ! is_array( $opt ) ) continue;
                        $opt_value = sanitize_text_field( (string) ( $opt['value'] ?? '' ) );
                        $opt_label = sanitize_text_field( (string) ( $opt['label'] ?? $opt_value ) );
                        $input_id  = $form_instance_id . '-' . $f_name . '-' . sanitize_html_class($opt_value);
                    ?>
                    <label class="esvy-radio-option" for="<?php echo esc_attr($input_id); ?>">
                        <input type="radio" id="<?php echo esc_attr($input_id); ?>"
                               name="<?php echo esc_attr($f_name); ?>"
                               value="<?php echo esc_attr($opt_value); ?>" />
                        <span class="esvy-radio-label"><?php echo esc_html($opt_label); ?></span>
                    </label>
                    <?php endforeach; ?>
                </div>

                <?php elseif ( $f_type === 'checkbox' ) :
                    $f_allow_other = ! empty( $field['allow_other'] );
                ?>
                <div class="esvy-checkbox-group" data-name="<?php echo esc_attr($f_name); ?>" data-allow-other="<?php echo $f_allow_other ? '1' : '0'; ?>">
                    <?php foreach ( $f_options as $opt ) :
                        if ( ! is_array( $opt ) ) continue;
                        $opt_value = sanitize_text_field( (string) ( $opt['value'] ?? '' ) );
                        $opt_label = sanitize_text_field( (string) ( $opt['label'] ?? $opt_value ) );
                        $input_id  = $form_instance_id . '-' . $f_name . '-' . sanitize_html_class($opt_value);
                    ?>
                    <label class="esvy-checkbox-option" for="<?php echo esc_attr($input_id); ?>">
                        <input type="checkbox" id="<?php echo esc_attr($input_id); ?>"
                               name="<?php echo esc_attr($f_name); ?>"
                               value="<?php echo esc_attr($opt_value); ?>" />
                        <span class="esvy-checkbox-label"><?php echo esc_html($opt_label); ?></span>
                    </label>
                    <?php endforeach; ?>

                    <?php if ( $f_allow_other ) :
                        $other_id = $form_instance_id . '-' . $f_name . '-other';
                    ?>
                    <label class="esvy-checkbox-option esvy-checkbox-option--other" for="<?php echo esc_attr($other_id); ?>">
                        <input type="checkbox" id="<?php echo esc_attr($other_id); ?>"
                               name="<?php echo esc_attr($f_name); ?>"
                               value="_other"
                               data-action="toggle-other" />
                        <span class="esvy-checkbox-label">Otro</span>
                    </label>
                    <div class="esvy-other-text-wrap" style="display:none">
                        <input type="text" class="esvy-input esvy-other-text"
                               name="<?php echo esc_attr($f_name); ?>_other_text"
                               placeholder="Especifica..."
                               autocomplete="off" />
                    </div>
                    <?php endif; ?>
                </div>

                <?php else : /* text / textarea */ ?>
                <div class="esvy-text-wrap" data-name="<?php echo esc_attr($f_name); ?>">
                    <textarea class="esvy-textarea" name="<?php echo esc_attr($f_name); ?>"
                              placeholder="<?php echo esc_attr( sanitize_text_field( (string) ( $field['placeholder'] ?? 'Escribe aquí tu respuesta…' ) ) ); ?>"
                              rows="4"></textarea>
                </div>

                <?php endif; ?>

            </div><!-- .esvy-question-body -->

            <div class="esvy-nav">
                <?php if ( $qi > 0 ) : ?>
                <button class="esvy-btn esvy-btn--ghost" data-action="prev" type="button">← Anterior</button>
                <?php endif; ?>

                <?php if ( ! $f_required ) : ?>
                <button class="esvy-btn esvy-btn--ghost" data-action="skip" type="button">Saltar</button>
                <?php endif; ?>

                <?php if ( $qi < $total_questions - 1 ) : ?>
                <button class="esvy-btn esvy-btn--primary" data-action="next" type="button">
                    Siguiente
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg>
                </button>
                <?php else : ?>
                <button class="esvy-btn esvy-btn--submit" data-action="submit" type="button">
                    Enviar respuesta ✓
                </button>
                <?php endif; ?>
            </div>

        </div><!-- .esvy-screen-inner -->
    </div><!-- .esvy-screen--question -->
    <?php endforeach; ?>

    <!-- COMPLETION SCREEN -->
    <div class="esvy-screen esvy-screen--completion" data-screen="completion">
        <div class="esvy-screen-inner esvy-completion-inner">
            <div class="esvy-completion-icon">✓</div>
            <h2 class="esvy-completion-title"><?php echo esc_html( $completion_title ); ?></h2>
            <p class="esvy-completion-message"><?php echo esc_html( $completion_message ); ?></p>
        </div>
    </div>

    <!-- PROGRESS BAR -->
    <div class="esvy-progress-wrap">
        <div class="esvy-progress-bar" data-progress="0"></div>
        <span class="esvy-progress-label">0 / <?php echo esc_html( $total_questions ); ?></span>
    </div>

</div><!-- .esvy-wrap -->
