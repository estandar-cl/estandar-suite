<?php

namespace Estandar\Capture;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * SurveyRenderer — render frontend de encuestas nativas v2 (F2).
 *
 * Puerto del render conversacional legacy (survey-render.php) al modelo nativo:
 * mismo DOM esvy-* (mismo CSS), mismo runtime JS, pero:
 *  - acciones AJAX nativas (estandar_capture_survey_*);
 *  - global JS propio (EstandarCaptureSurvey) — sin colisión con legacy activo;
 *  - config leída del config_json nativo (sections/fields_json + survey_config).
 *
 * Soporta: hero, identificación (anonymous/identified/optional), preguntas
 * (nps/rating/matrix/emoji/cards/select/radio/checkbox/text/textarea), completion
 * y barra de progreso. Branching via flow_map (ConversationEngine).
 */
class SurveyRenderer
{
	/** ¿Ya se inyectaron los <link> de CSS (forms + survey) en este request? */
	private static $css_injected = false;

	/** Campos de identidad que se guardan en el navegador (remember_identity). */
	const IDENTITY_FIELDS = array( 'proyecto', 'calle', 'nombre_propietario', 'nombre', 'apellido', 'rut', 'telefono', 'email' );

	public static function render_form( array $form ): string
	{
		$id     = (int) $form['id'];
		$config = json_decode( (string) ( $form['config_json'] ?? '{}' ), true );
		$config = is_array( $config ) ? $config : array();
		$type   = sanitize_key( (string) ( $form['form_type'] ?? 'survey' ) );

		// F4 · perfil por tipo: survey → survey_config; experiencias (quote,
		// diagnostico, onboarding, recovery, support, assessment,
		// lead_qualification) → conversation_config. Motor único: un form
		// incident/postventa con step_by_step activo también usa survey_config.
		$survey_config = isset( $config['survey_config'] )
			? $config['survey_config']
			: ( isset( $config['conversation_config'] ) ? $config['conversation_config'] : array() );
		if ( is_string( $survey_config ) ) {
			$survey_config = json_decode( $survey_config, true );
		}
		$survey_config = is_array( $survey_config ) ? $survey_config : array();

		$engine_cfg = ConversationEngine::normalize_config( $survey_config );

		$id_mode            = sanitize_key( (string) ( $survey_config['identification_mode'] ?? ( $engine_cfg['identification'] ?? 'anonymous' ) ) );
		$remember_identity  = ! empty( $survey_config['remember_identity'] );
		$hero_title         = sanitize_text_field( (string) ( $survey_config['hero_title'] ?? '' ) );
		$hero_subtitle      = sanitize_text_field( (string) ( $survey_config['hero_subtitle'] ?? '' ) );
		$hero_cta           = sanitize_text_field( (string) ( $survey_config['hero_cta'] ?? 'Comenzar' ) );
		$hero_image_url     = esc_url( (string) ( $survey_config['hero_image_url'] ?? '' ) );
		$completion_title   = sanitize_text_field( (string) ( $survey_config['completion_title'] ?? '¡Gracias!' ) );
		$completion_message = sanitize_text_field( (string) ( $survey_config['completion_message'] ?? 'Tu respuesta fue registrada exitosamente.' ) );

		$fields = Forms::renderable_fields( $config );
		$questions = array();
		foreach ( $fields as $field ) {
			if ( is_array( $field ) && ! empty( $field['type'] ) && ! empty( $field['name'] ) ) {
				$questions[] = $field;
			}
		}
		$total = count( $questions );
		if ( $total === 0 ) {
			return '';
		}

		static $survey_instance = 0;
		$survey_instance++;
		$instance_id = 'survey-' . $id . '-' . $survey_instance;

		$show_hero           = $hero_title !== '' || $hero_subtitle !== '';
		$show_identification = $id_mode !== 'anonymous';
		$flow_map            = ConversationEngine::flow_map( $engine_cfg, $questions );

		$steps_to_end = self::steps_to_end( $flow_map, $total );

		$inline_assets = self::inline_assets( $id, $total, $id_mode, $instance_id, $flow_map, $remember_identity, $steps_to_end );

		ob_start();
		?>
		<div class="esvy-wrap" id="<?php echo esc_attr( $instance_id ); ?>"
		     data-form-id="<?php echo esc_attr( $id ); ?>"
		     data-total="<?php echo esc_attr( $total ); ?>"
		     data-id-mode="<?php echo esc_attr( $id_mode ); ?>"
		     data-remember-identity="<?php echo $remember_identity ? '1' : '0'; ?>">

			<?php if ( $hero_image_url !== '' ) : ?>
			<div class="esvy-bg" style="background-image:url('<?php echo esc_attr( $hero_image_url ); ?>')"></div>
			<?php endif; ?>

			<?php if ( $remember_identity ) : ?>
			<!-- Reconocimiento de identidad persistente: se muestra vía JS si el
			     navegador tiene identidad guardada (localStorage). -->
			<div class="esvy-screen esvy-screen--recognize" data-screen="recognize" style="display:none;">
				<div class="esvy-screen-inner">
					<p class="esvy-screen-label esvy-screen-label--hint">Bienvenido de vuelta</p>
					<h3 class="esvy-question-title" data-role="recognize-title">Hola, ¿necesitas otro ticket de postventa?</h3>
					<p class="esvy-question-hint" data-role="recognize-subtitle">Ya tenemos tus datos guardados en este dispositivo. ¿Quieres continuar sin volver a llenarlos?</p>
					<div class="esvy-nav" data-role="recognize-actions">
						<button class="esvy-btn esvy-btn--primary" data-action="recognize-yes" type="button">Sí, continuar</button>
						<button class="esvy-btn esvy-btn--ghost" data-action="recognize-no" type="button">No, soy otra persona</button>
					</div>
				</div>
			</div>
			<?php endif; ?>

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
							<label class="esvy-label" for="<?php echo esc_attr( $instance_id ); ?>-respondent-name">Nombre</label>
							<input class="esvy-input" type="text" id="<?php echo esc_attr( $instance_id ); ?>-respondent-name"
							       name="respondent_name" placeholder="Tu nombre" autocomplete="name" />
						</div>
						<div class="esvy-field-group">
							<label class="esvy-label" for="<?php echo esc_attr( $instance_id ); ?>-respondent-email">Email</label>
							<input class="esvy-input" type="email" id="<?php echo esc_attr( $instance_id ); ?>-respondent-email"
							       name="respondent_email" placeholder="correo@ejemplo.com" autocomplete="email" />
						</div>
						<div class="esvy-field-group">
							<label class="esvy-label" for="<?php echo esc_attr( $instance_id ); ?>-respondent-phone">Teléfono</label>
							<input class="esvy-input" type="tel" id="<?php echo esc_attr( $instance_id ); ?>-respondent-phone"
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

			<?php foreach ( $questions as $qi => $field ) :
				$f_name     = sanitize_key( (string) $field['name'] );
				$f_type     = sanitize_key( (string) $field['type'] );
				$f_label    = sanitize_text_field( (string) ( $field['label'] ?? '' ) );
				$f_hint     = sanitize_text_field( (string) ( $field['hint'] ?? '' ) );
				$f_required = ! empty( $field['required'] );
				$f_options  = is_array( $field['options'] ?? null ) ? $field['options'] : array();
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
			     data-required="<?php echo $f_required ? '1' : '0'; ?>"
			     <?php echo in_array( $f_name, self::IDENTITY_FIELDS, true ) ? 'data-identity="1"' : ''; ?>>

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
						<div class="esvy-nps" data-name="<?php echo esc_attr( $f_name ); ?>">
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
						<div class="esvy-rating" data-name="<?php echo esc_attr( $f_name ); ?>" data-max="<?php echo esc_attr( $f_max ); ?>">
							<?php for ( $s = 1; $s <= $f_max; $s++ ) : ?>
							<button class="esvy-star-btn" data-value="<?php echo $s; ?>" type="button" aria-label="<?php echo $s; ?> estrella<?php echo $s > 1 ? 's' : ''; ?>">
								<svg viewBox="0 0 24 24"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>
							</button>
							<?php endfor; ?>
						</div>

						<?php elseif ( $f_type === 'matrix' ) :
							$f_rows     = is_array( $field['rows'] ?? null ) ? $field['rows'] : array();
							$f_scale    = (int) ( $field['scale'] ?? 7 );
							$f_lbl_min  = sanitize_text_field( (string) ( $field['label_min'] ?? '' ) );
							$f_lbl_max  = sanitize_text_field( (string) ( $field['label_max'] ?? '' ) );
							$f_col_lbls = is_array( $field['column_labels'] ?? null ) ? $field['column_labels'] : array();
						?>
						<div class="esvy-matrix" data-name="<?php echo esc_attr( $f_name ); ?>" data-required="<?php echo $f_required ? '1' : '0'; ?>" data-scale="<?php echo esc_attr( $f_scale ); ?>">
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
										<tr class="esvy-matrix-row" data-row-id="<?php echo esc_attr( $row_id ); ?>">
											<td class="esvy-matrix-row-label"><?php echo esc_html( $row_label ); ?></td>
											<?php for ( $s = 1; $s <= $f_scale; $s++ ) :
												$hue = $f_scale > 1 ? round( ( $s - 1 ) / ( $f_scale - 1 ) * 120 ) : 60;
											?>
											<td class="esvy-matrix-cell">
												<button class="esvy-matrix-btn" data-row-id="<?php echo esc_attr( $row_id ); ?>" data-value="<?php echo $s; ?>" type="button"
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
							<div class="esvy-matrix-mobile">
								<?php foreach ( $f_rows as $ri => $row ) :
									$row_id    = sanitize_key( (string) ( $row['id'] ?? 'row_' . $ri ) );
									$row_label = sanitize_text_field( (string) ( $row['label'] ?? '' ) );
								?>
								<div class="esvy-matrix-mobile-row" data-row-id="<?php echo esc_attr( $row_id ); ?>">
									<div class="esvy-matrix-mobile-label"><?php echo esc_html( $row_label ); ?></div>
									<div class="esvy-matrix-mobile-btns">
										<?php for ( $s = 1; $s <= $f_scale; $s++ ) :
											$hue = $f_scale > 1 ? round( ( $s - 1 ) / ( $f_scale - 1 ) * 120 ) : 60;
										?>
										<button class="esvy-matrix-btn esvy-matrix-btn--mobile" data-row-id="<?php echo esc_attr( $row_id ); ?>" data-value="<?php echo $s; ?>" type="button"
										        style="--esvy-matrix-h:<?php echo (int) $hue; ?>"><?php echo $s; ?></button>
										<?php endfor; ?>
									</div>
								</div>
								<?php endforeach; ?>
							</div>
						</div>

						<?php elseif ( $f_type === 'emoji' ) : ?>
						<div class="esvy-emoji" data-name="<?php echo esc_attr( $f_name ); ?>">
							<?php foreach ( $f_options as $opt ) :
								if ( ! is_array( $opt ) ) continue;
								$opt_value = sanitize_text_field( (string) ( $opt['value'] ?? '' ) );
								$opt_label = sanitize_text_field( (string) ( $opt['label'] ?? '' ) );
								$opt_emoji = sanitize_text_field( (string) ( $opt['emoji'] ?? '⭐' ) );
							?>
							<button class="esvy-emoji-btn" data-value="<?php echo esc_attr( $opt_value ); ?>"
							        data-label="<?php echo esc_attr( $opt_label ); ?>" type="button">
								<span class="esvy-emoji-icon"><?php echo esc_html( $opt_emoji ); ?></span>
								<span class="esvy-emoji-label"><?php echo esc_html( $opt_label ); ?></span>
							</button>
							<?php endforeach; ?>
						</div>

						<?php elseif ( $f_type === 'cards' ) : ?>
						<div class="esvy-cards" data-name="<?php echo esc_attr( $f_name ); ?>">
							<?php foreach ( $f_options as $opt ) :
								if ( ! is_array( $opt ) ) continue;
								$opt_value = sanitize_text_field( (string) ( $opt['value'] ?? '' ) );
								$opt_label = sanitize_text_field( (string) ( $opt['label'] ?? '' ) );
								$opt_desc  = sanitize_text_field( (string) ( $opt['description'] ?? '' ) );
								$opt_icon  = sanitize_text_field( (string) ( $opt['icon'] ?? '' ) );
							?>
							<button class="esvy-card" data-value="<?php echo esc_attr( $opt_value ); ?>"
							        data-label="<?php echo esc_attr( $opt_label ); ?>" type="button">
								<?php if ( $opt_icon !== '' ) : ?>
								<span class="esvy-card-icon"><?php echo esc_html( $opt_icon ); ?></span>
								<?php endif; ?>
								<span class="esvy-card-label"><?php echo esc_html( $opt_label ); ?></span>
								<?php if ( $opt_desc !== '' ) : ?>
								<span class="esvy-card-desc"><?php echo esc_html( $opt_desc ); ?></span>
								<?php endif; ?>
							</button>
							<?php endforeach; ?>
						</div>

						<?php elseif ( $f_type === 'select' ) : ?>
						<div class="esvy-select-wrap" data-name="<?php echo esc_attr( $f_name ); ?>">
							<select class="esvy-select" name="<?php echo esc_attr( $f_name ); ?>">
								<option value="">— Selecciona una opción —</option>
								<?php foreach ( $f_options as $opt ) :
									if ( ! is_array( $opt ) ) continue;
									$opt_value = sanitize_text_field( (string) ( $opt['value'] ?? '' ) );
									$opt_label = sanitize_text_field( (string) ( $opt['label'] ?? $opt_value ) );
								?>
								<option value="<?php echo esc_attr( $opt_value ); ?>"><?php echo esc_html( $opt_label ); ?></option>
								<?php endforeach; ?>
							</select>
						</div>

						<?php elseif ( $f_type === 'radio' ) :
							$f_allow_other = ! empty( $field['allow_other'] );
						?>
						<div class="esvy-radio-group" data-name="<?php echo esc_attr( $f_name ); ?>" data-allow-other="<?php echo $f_allow_other ? '1' : '0'; ?>">
							<?php foreach ( $f_options as $opt ) :
								if ( ! is_array( $opt ) ) continue;
								$opt_value = sanitize_text_field( (string) ( $opt['value'] ?? '' ) );
								$opt_label = sanitize_text_field( (string) ( $opt['label'] ?? $opt_value ) );
								$input_id  = $instance_id . '-' . $f_name . '-' . sanitize_html_class( $opt_value );
							?>
							<label class="esvy-radio-option" for="<?php echo esc_attr( $input_id ); ?>">
								<input type="radio" id="<?php echo esc_attr( $input_id ); ?>"
								       name="<?php echo esc_attr( $f_name ); ?>"
								       value="<?php echo esc_attr( $opt_value ); ?>" />
								<span class="esvy-radio-label"><?php echo esc_html( $opt_label ); ?></span>
							</label>
							<?php endforeach; ?>
							<?php if ( $f_allow_other ) :
								$other_id = $instance_id . '-' . $f_name . '-other';
							?>
							<label class="esvy-radio-option esvy-radio-option--other" for="<?php echo esc_attr( $other_id ); ?>">
								<input type="radio" id="<?php echo esc_attr( $other_id ); ?>"
								       name="<?php echo esc_attr( $f_name ); ?>"
								       value="_other" data-action="toggle-other-radio" />
								<span class="esvy-radio-label">Otra</span>
							</label>
							<div class="esvy-other-text-wrap" style="display:none">
								<input type="text" class="esvy-input esvy-other-text"
								       name="<?php echo esc_attr( $f_name ); ?>_other_text"
								       placeholder="Escribe tu requerimiento..." autocomplete="off" />
							</div>
							<?php endif; ?>
						</div>

						<?php elseif ( $f_type === 'checkbox' ) :
							$f_allow_other = ! empty( $field['allow_other'] );
						?>
						<div class="esvy-checkbox-group" data-name="<?php echo esc_attr( $f_name ); ?>" data-allow-other="<?php echo $f_allow_other ? '1' : '0'; ?>">
							<?php foreach ( $f_options as $opt ) :
								if ( ! is_array( $opt ) ) continue;
								$opt_value = sanitize_text_field( (string) ( $opt['value'] ?? '' ) );
								$opt_label = sanitize_text_field( (string) ( $opt['label'] ?? $opt_value ) );
								$input_id  = $instance_id . '-' . $f_name . '-' . sanitize_html_class( $opt_value );
							?>
							<label class="esvy-checkbox-option" for="<?php echo esc_attr( $input_id ); ?>">
								<input type="checkbox" id="<?php echo esc_attr( $input_id ); ?>"
								       name="<?php echo esc_attr( $f_name ); ?>"
								       value="<?php echo esc_attr( $opt_value ); ?>" />
								<span class="esvy-checkbox-label"><?php echo esc_html( $opt_label ); ?></span>
							</label>
							<?php endforeach; ?>
							<?php if ( $f_allow_other ) :
								$other_id = $instance_id . '-' . $f_name . '-other';
							?>
							<label class="esvy-checkbox-option esvy-checkbox-option--other" for="<?php echo esc_attr( $other_id ); ?>">
								<input type="checkbox" id="<?php echo esc_attr( $other_id ); ?>"
								       name="<?php echo esc_attr( $f_name ); ?>"
								       value="_other" data-action="toggle-other" />
								<span class="esvy-checkbox-label">Otro</span>
							</label>
							<div class="esvy-other-text-wrap" style="display:none">
								<input type="text" class="esvy-input esvy-other-text"
								       name="<?php echo esc_attr( $f_name ); ?>_other_text"
								       placeholder="Especifica..." autocomplete="off" />
							</div>
							<?php endif; ?>
						</div>

						<?php elseif ( $f_type === 'dependent_select' ) :
							$f_depends = sanitize_key( (string) ( $field['depends_on'] ?? '' ) );
							// GENERIC_DEPENDENT_MAP_V1: si el campo define `options_map`
							// (mapa valor-padre → opciones), se honra igual que en
							// Fields::dependent_select_html (paridad lead/survey). Sin
							// ello → fallback al mapa de calles por proyecto.
							$f_map = ( isset( $field['options_map'] ) && is_array( $field['options_map'] ) && $field['options_map'] ) ? $field['options_map'] : array();
							if ( $f_map ) {
								$map_attr = ' data-options-map="' . esc_attr( (string) wp_json_encode( $f_map ) ) . '"';
							} else {
								$f_streets = \Estandar\Capture\Fields::streets_map();
								$map_attr  = $f_streets ? ' data-streets="' . esc_attr( (string) wp_json_encode( $f_streets ) ) . '"' : '';
							}
						?>
						<div class="esvy-select-wrap" data-name="<?php echo esc_attr( $f_name ); ?>" data-question-type="select">
							<select class="esvy-select esvy-dependent-select" name="<?php echo esc_attr( $f_name ); ?>"
							        data-depends-on="<?php echo esc_attr( $f_depends ); ?>"<?php echo $map_attr; ?>>
								<option value="">— Selecciona primero el campo anterior —</option>
							</select>
							<?php if ( $f_depends !== '' && function_exists( 'admin_url' ) ) : ?>
							<span class="esvy-dependent-nonce" data-nonce="<?php echo esc_attr( wp_create_nonce( 'ef_get_streets' ) ); ?>" data-ajax="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>"></span>
							<?php endif; ?>
						</div>

						<?php elseif ( in_array( $f_type, array( 'text', 'email', 'tel', 'rut', 'number', 'url' ), true ) ) : ?>
						<div class="esvy-text-wrap" data-name="<?php echo esc_attr( $f_name ); ?>">
							<?php
							$f_input_type = in_array( $f_type, array( 'email', 'tel', 'number', 'url' ), true ) ? $f_type : 'text';
							$f_placeholder = sanitize_text_field( (string) ( $field['placeholder'] ?? '' ) );
							if ( $f_type === 'rut' ) {
								$f_input_type = 'text';
								$f_placeholder = $f_placeholder !== '' ? $f_placeholder : '12.345.678-9';
							}
							?>
							<input class="esvy-input<?php echo $f_type === 'rut' ? ' esvy-rut-field' : ''; ?>" type="<?php echo esc_attr( $f_input_type ); ?>"
							       name="<?php echo esc_attr( $f_name ); ?>" placeholder="<?php echo esc_attr( $f_placeholder ); ?>"
							       autocomplete="<?php echo $f_type === 'email' ? 'email' : ( $f_type === 'tel' ? 'tel' : 'off' ); ?>" />
						</div>

						<?php else : ?>
						<div class="esvy-text-wrap" data-name="<?php echo esc_attr( $f_name ); ?>">
							<?php
							$f_max = (int) ( $field['max_length'] ?? 0 );
							$f_rows = (int) ( $field['rows'] ?? 5 );
							if ( $f_rows < 3 || $f_rows > 10 ) { $f_rows = 5; }
							?>
							<textarea class="esvy-textarea" name="<?php echo esc_attr( $f_name ); ?>"
							          placeholder="<?php echo esc_attr( sanitize_text_field( (string) ( $field['placeholder'] ?? 'Escribe aquí tu respuesta…' ) ) ); ?>"
							          rows="<?php echo esc_attr( (string) $f_rows ); ?>"
							          <?php if ( $f_max > 0 ) : ?>maxlength="<?php echo esc_attr( (string) $f_max ); ?>"<?php endif; ?>></textarea>
							<?php if ( $f_max > 0 ) : ?><div class="esvy-text-counter" data-role="char-counter" data-max="<?php echo esc_attr( (string) $f_max ); ?>" style="font-size:.78rem;color:var(--esvy-muted);text-align:right;margin-top:4px;">0 / <?php echo esc_html( (string) $f_max ); ?></div><?php endif; ?>

							<?php if ( strpos( $f_name, 'problema_' ) === 0 ) : $upload_id = $instance_id . '-' . $f_name . '-photo'; ?>
							<div class="esvy-photo-block" data-role="photo-block">
								<label class="esvy-photo-toggle" for="<?php echo esc_attr( $upload_id ); ?>">
									<input type="checkbox" class="esvy-photo-checkbox" data-role="photo-toggle"
									       id="<?php echo esc_attr( $upload_id ); ?>" autocomplete="off" />
									<span class="esvy-photo-toggle-label">Adjuntar imagen</span>
								</label>
								<div class="esvy-photo-upload" data-role="photo-upload" hidden>
									<label class="esvy-photo-file-label" for="<?php echo esc_attr( $upload_id ); ?>-file">
										<span data-role="photo-file-label">Subir imagen o tomar con la cámara</span>
									</label>
									<input type="file" class="esvy-photo-file" data-role="photo-file"
									       id="<?php echo esc_attr( $upload_id ); ?>-file"
									       name="<?php echo esc_attr( $f_name ); ?>_foto"
									       accept="image/*" hidden />
									<div class="esvy-photo-preview" data-role="photo-preview" hidden>
										<img data-role="photo-preview-img" alt="Vista previa de la imagen" />
										<button type="button" class="esvy-photo-remove" data-role="photo-remove" aria-label="Quitar imagen">✕</button>
									</div>
									<input type="hidden" class="esvy-photo-url" data-role="photo-url" value="" />
									<p class="esvy-photo-error" data-role="photo-error" hidden></p>
								</div>
							</div>
							<?php endif; ?>
						</div>

						<?php endif; ?>

					</div>

					<div class="esvy-nav">
						<?php if ( $qi > 0 ) : ?>
						<button class="esvy-btn esvy-btn--ghost" data-action="prev" type="button">← Anterior</button>
						<?php endif; ?>

						<?php if ( ! $f_required ) : ?>
						<button class="esvy-btn esvy-btn--ghost" data-action="skip" type="button">Saltar</button>
						<?php endif; ?>

						<?php if ( $qi < $total - 1 ) : ?>
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

				</div>
			</div>
			<?php endforeach; ?>

			<div class="esvy-screen esvy-screen--completion" data-screen="completion">
				<div class="esvy-screen-inner esvy-completion-inner">
					<?php $anim = Forms::success_animation( $config ); if ( $anim['mode'] !== 'none' && $anim['url'] !== '' ) : ?>
					<img src="<?php echo esc_url( $anim['url'] ); ?>" alt="" aria-hidden="true" style="width:110px;height:auto;display:block;margin:0 auto 16px;">
					<?php endif; ?>
					<h2 class="esvy-completion-title"><?php echo esc_html( $completion_title ); ?></h2>
					<p class="esvy-completion-message"><?php echo esc_html( $completion_message ); ?></p>
				</div>
			</div>

			<div class="esvy-progress-wrap">
				<div class="esvy-progress-bar" data-progress="0"></div>
				<span class="esvy-progress-label">0 / <?php echo esc_html( $total ); ?></span>
			</div>

		</div>
		<?php
		return $inline_assets . (string) ob_get_clean();
	}

	/** <style> INLINE de forms + survey (contenido real de los archivos). */
	private static function css_inline(): string
	{
		if ( self::$css_injected || ! defined( 'ESTANDAR_SUITE_CAPTURE_PATH' ) ) {
			return '';
		}
		self::$css_injected = true;
		$out = '';
		foreach ( array( 'estandar-form.css', 'estandar-survey.css' ) as $css_file ) {
			$path = ESTANDAR_SUITE_CAPTURE_PATH . 'assets/css/' . $css_file;
			if ( is_file( $path ) ) {
				$css = (string) file_get_contents( $path );
				$css = str_replace( '</style', '<\\/style', $css );
				$out .= '<style id="es-' . sanitize_key( $css_file ) . '-inline">' . $css . '</style>';
			}
		}
		return $out;
	}

	/** <script> inline del survey con su config localizada. El CSS se inyecta
	 *  por JS (los <style> en body los elimina wp_kses en the_content; los
	 *  <script> sí sobreviven). */
	private static function survey_script_inline( array $localize ): string
	{
		if ( ! defined( 'ESTANDAR_SUITE_CAPTURE_PATH' ) ) {
			return '';
		}
		$path = ESTANDAR_SUITE_CAPTURE_PATH . 'assets/js/estandar-survey.js';
		if ( ! is_file( $path ) ) {
			return '';
		}
		$js = (string) file_get_contents( $path );
		$js = str_replace( '</script', '<\\/script', $js );

		// CSS inyectado por JS (evita kses/theme del body).
		$css_js = '';
		foreach ( array( 'estandar-form.css', 'estandar-survey.css' ) as $css_file ) {
			$css_path = ESTANDAR_SUITE_CAPTURE_PATH . 'assets/css/' . $css_file;
			if ( is_file( $css_path ) ) {
				$css = (string) file_get_contents( $css_path );
				$css = str_replace( '</style', '<\\/style', $css );
				$css = str_replace( array( "\r", "\n" ), '', $css );
				$css_js .= 'var s=document.createElement("style");s.id="es-' . sanitize_key( $css_file ) . '-inline";s.textContent=' . wp_json_encode( $css ) . ';document.head.appendChild(s);';
			}
		}

		return '<script id="estandar-survey-inline">var EstandarCaptureSurvey=' . wp_json_encode( $localize ) . ';</script>'
			. '<script>try{' . $css_js . '}catch(e){}</script>'
			. '<script>' . $js . '</script>';
	}

	/**
	 * Compone los assets inline del survey: <script> con config + inyector de
	 * CSS (el <style> en body lo elimina kses; el <script> sobrevive).
	 * El CSS también se enqueuea en wp_head desde FormRenderer.
	 */
	private static function inline_assets( int $form_id, int $total, string $id_mode, string $instance_id, array $flow_map, bool $remember_identity = false, array $steps_to_end = array() ): string
	{
		$localize = array(
			'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
			'nonce'          => wp_create_nonce( SurveyHandler::NONCE ),
			'formId'         => $form_id,
			'totalQuestions' => $total,
			'idMode'         => $id_mode,
			'instanceId'     => $instance_id,
			'nextMap'        => $flow_map,
			'stepsToEnd'     => $steps_to_end,
			'rememberIdentity' => $remember_identity ? 1 : 0,
		);
		return self::survey_script_inline( $localize );
	}

	/**
	 * Pasos MÁXIMOS hasta el fin (incluyendo la pregunta actual) por índice,
	 * respetando el branching del flow_map. La barra de progreso usa esto para
	 * reflejar el camino REAL del usuario: si el flujo se corta antes (ej. solo
	 * documentación), el total mostrado es menor y la posición es honesta.
	 *
	 * @return array<int,int> steps_to_end[i] = 1 + max(steps de los próximos).
	 */
	private static function steps_to_end( array $flow_map, int $total ): array
	{
		$steps = array();
		$memo  = array();
		$walk  = null;
		$walk  = static function ( int $i ) use ( &$walk, &$memo, $flow_map, $total ): int {
			if ( isset( $memo[ $i ] ) ) {
				return $memo[ $i ];
			}
			// Próximos índices reales (reglas + catchall que NO sean 'end').
			$nexts = array();
			if ( isset( $flow_map[ $i ] ) ) {
				foreach ( (array) ( $flow_map[ $i ]['rules'] ?? array() ) as $r ) {
					if ( isset( $r['next'] ) && is_int( $r['next'] ) ) {
						$nexts[] = $r['next'];
					}
				}
				$catch = $flow_map[ $i ]['catchall'] ?? null;
				if ( is_int( $catch ) ) {
					$nexts[] = $catch;
				}
			}
			// Sin mapa (pregunta lineal) → siguiente pregunta o fin.
			if ( $nexts === array() && $i + 1 < $total ) {
				$nexts[] = $i + 1;
			}
			// Peor caso: la rama más larga que queda desde aquí.
			$worst = 0;
			foreach ( $nexts as $n ) {
				$worst = max( $worst, $walk( $n ) );
			}
			$memo[ $i ] = 1 + $worst;
			return $memo[ $i ];
		};
		for ( $i = $total - 1; $i >= 0; $i-- ) {
			$steps[ $i ] = $walk( $i );
		}
		return $steps;
	}
}
