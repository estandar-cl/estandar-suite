<?php

namespace Estandar\Capture;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * GfImporter — Gravity Forms → formularios nativos v2 (F4).
 *
 * Puerto del Estandar_Forms_GF_Importer legacy al modelo nativo:
 * detect → analyze → import, con el mismo TYPE_MAP/SPLIT_FIELDS. El import
 * crea el form en `estandar_capture_forms` (config_json con fields_json).
 */
class GfImporter
{
	private const TYPE_MAP = array(
		'text'        => 'text',
		'textarea'    => 'textarea',
		'email'       => 'email',
		'phone'       => 'tel',
		'select'      => 'select',
		'radio'       => 'radio',
		'checkbox'    => 'checkbox',
		'number'      => 'number',
		'date'        => 'date',
		'fileupload'  => 'file',
		'hidden'      => 'hidden',
		'website'     => 'text',
		'html'        => 'html',
		'list'        => 'select',
		'multiselect' => 'select',
		'name'        => 'name',
		'address'     => 'address',
		'section'     => 'section',
		'page'        => 'page',
		'captcha'     => null,
		'post_title'  => 'text',
		'post_content' => 'textarea',
		'post_excerpt' => 'textarea',
		'post_tags'   => 'text',
		'post_category' => 'select',
		'post_image'  => 'file',
		'post_custom_field' => 'text',
	);

	private const SPLIT_FIELDS = array(
		'name' => array(
			array( 'name' => 'nombre', 'label' => 'Nombre', 'required' => true ),
			array( 'name' => 'apellido', 'label' => 'Apellido', 'required' => true ),
		),
	);

	private array $source = array();
	private array $report = array();
	private array $lost_fields = array();

	/** @param string|array $json GF form JSON string o array. */
	public function __construct( $json )
	{
		if ( is_string( $json ) ) {
			$json = json_decode( $json, true );
		}
		if ( ! is_array( $json ) ) {
			$this->report['error'] = 'JSON inválido.';
			return;
		}
		$this->source = $json;
	}

	public function is_gravity_forms(): bool
	{
		if ( empty( $this->source ) ) {
			return false;
		}
		return isset( $this->source['fields'] )
			&& is_array( $this->source['fields'] )
			&& isset( $this->source['id'] )
			&& isset( $this->source['title'] );
	}

	/** @return array report de compatibilidad (can_import >= 70%). */
	public function analyze(): array
	{
		if ( ! $this->is_gravity_forms() ) {
			return array( 'can_import' => false, 'error' => 'El archivo no es un formulario Gravity Forms válido.' );
		}

		$fields = (array) ( $this->source['fields'] ?? array() );
		$total  = count( $fields );
		$mapped = 0;
		$lost   = 0;
		$split  = 0;
		$unsupported = array();

		foreach ( $fields as $f ) {
			$gf_type = sanitize_key( (string) ( $f['type'] ?? '' ) );
			$ef_type = self::TYPE_MAP[ $gf_type ] ?? null;

			if ( $ef_type === null ) {
				$lost++;
				$unsupported[ $gf_type ] = ( $unsupported[ $gf_type ] ?? 0 ) + 1;
				$this->lost_fields[] = array(
					'gf_id'   => (int) ( $f['id'] ?? 0 ),
					'gf_type' => $gf_type,
					'label'   => sanitize_text_field( (string) ( $f['label'] ?? ( $f['adminLabel'] ?? '' ) ) ),
					'reason'  => 'Tipo no soportado en Estandar Capture.',
				);
			} elseif ( $ef_type === 'section' || $ef_type === 'page' ) {
				$lost++;
				$this->lost_fields[] = array(
					'gf_id'   => (int) ( $f['id'] ?? 0 ),
					'gf_type' => $gf_type,
					'label'   => sanitize_text_field( (string) ( $f['label'] ?? '' ) ),
					'reason'  => 'Sin equivalente de sección/página; se agrupa en el layout.',
				);
			} elseif ( isset( self::SPLIT_FIELDS[ $gf_type ] ) ) {
				$split += count( self::SPLIT_FIELDS[ $gf_type ] );
			} else {
				$mapped++;
			}
		}

		$compatibility = $total > 0 ? round( ( $mapped + $split ) / $total * 100, 1 ) : 0;
		$this->report = array(
			'can_import'        => $compatibility >= 70,
			'source_title'      => sanitize_text_field( (string) ( $this->source['title'] ?? 'Sin título' ) ),
			'source_id'         => (int) ( $this->source['id'] ?? 0 ),
			'total_fields'      => $total,
			'mapped_fields'     => $mapped,
			'split_fields'      => $split,
			'lost_fields'       => $lost,
			'compatibility_pct' => $compatibility,
			'unsupported_types' => $unsupported,
			'lost_fields_detail' => $this->lost_fields,
		);
		return $this->report;
	}

	/** Importa el form GF → nativo v2. @return array form_id/slug/name/shortcode/report. */
	public function import( array $options = array() ): array
	{
		if ( ! $this->is_gravity_forms() ) {
			return array( 'error' => 'No es un formulario Gravity Forms válido.' );
		}
		$this->analyze();

		$name = sanitize_text_field( (string) ( $this->source['title'] ?? 'Formulario importado' ) );
		$config = array(
			'fields_json'     => $this->convert_fields(),
			'success_message' => sanitize_text_field( (string) ( $options['success_message'] ?? 'Gracias. Hemos recibido tu información correctamente.' ) ),
			'submit_label'    => sanitize_text_field( (string) ( $this->source['button']['text'] ?? 'Enviar' ) ),
			'notify_emails'   => sanitize_text_field( (string) ( $options['notify_emails'] ?? '' ) ),
		);
		if ( ! empty( $options['incidents_config'] ) ) {
			$config['incidents_config'] = $options['incidents_config'];
		}
		if ( ! empty( $options['actions_json'] ) ) {
			$config['actions_json'] = $options['actions_json'];
		}

		$form_id = Forms::create( array(
			'name'         => $name,
			'form_type'    => sanitize_key( (string) ( $options['form_type'] ?? 'lead' ) ),
			'form_context' => sanitize_key( (string) ( $options['form_context'] ?? 'generic' ) ),
			'status'       => 'active',
			'config_json'  => wp_json_encode( $config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
		) );

		if ( $form_id <= 0 ) {
			return array( 'error' => 'No se pudo crear el formulario nativo.' );
		}

		return array(
			'form_id'   => $form_id,
			'slug'      => (string) ( Forms::get( $form_id )['slug'] ?? '' ),
			'name'      => $name,
			'shortcode' => '[estandar_form_v2 id="' . $form_id . '"]',
			'report'    => $this->report,
		);
	}

	/** @return array campos convertidos GF → v2. */
	public function convert_fields(): array
	{
		$gf_fields = (array) ( $this->source['fields'] ?? array() );
		$ef_fields = array();

		foreach ( $gf_fields as $f ) {
			if ( empty( $f['type'] ) ) {
				continue;
			}
			if ( (string) ( $f['visibility'] ?? 'visible' ) !== 'visible' ) {
				continue;
			}
			$gf_type = sanitize_key( (string) $f['type'] );

			if ( isset( self::SPLIT_FIELDS[ $gf_type ] ) ) {
				foreach ( self::SPLIT_FIELDS[ $gf_type ] as $split_def ) {
					$ef_fields[] = $this->build_split_field( $f, $split_def );
				}
				continue;
			}

			$ef_type = self::TYPE_MAP[ $gf_type ] ?? null;
			if ( $ef_type === null || in_array( $ef_type, array( 'section', 'page' ), true ) ) {
				continue;
			}
			$field = $this->build_field( $f, $ef_type );
			if ( $field ) {
				$ef_fields[] = $field;
			}
		}
		return $ef_fields;
	}

	private function build_split_field( array $gf, array $split_def ): array
	{
		$field = array(
			'type'     => 'text',
			'name'     => sanitize_key( (string) $split_def['name'] ),
			'label'    => sanitize_text_field( (string) $split_def['label'] ),
			'required' => ! empty( $gf['isRequired'] ),
		);
		return $field;
	}

	private function build_field( array $gf, string $ef_type ): ?array
	{
		$label = sanitize_text_field( (string) ( $gf['label'] ?? ( $gf['adminLabel'] ?? '' ) ) );
		$admin = sanitize_key( (string) ( $gf['adminLabel'] ?? '' ) );
		$name  = $admin !== '' ? $admin : sanitize_title( $label );
		$name  = $name !== '' ? $name : $ef_type . '_' . (int) ( $gf['id'] ?? 0 );

		$field = array(
			'type'     => $ef_type,
			'name'     => $name,
			'label'    => $label !== '' ? $label : $name,
			'required' => ! empty( $gf['isRequired'] ),
		);

		$hint = sanitize_text_field( (string) ( $gf['description'] ?? '' ) );
		if ( $hint !== '' ) {
			$field['hint'] = $hint;
		}

		if ( in_array( $ef_type, array( 'select', 'radio', 'checkbox' ), true ) ) {
			$choices = $gf['choices'] ?? array();
			if ( is_array( $choices ) ) {
				$opts = array();
				foreach ( $choices as $c ) {
					if ( is_array( $c ) ) {
						$val = sanitize_text_field( (string) ( $c['value'] ?? '' ) );
						$txt = sanitize_text_field( (string) ( $c['text'] ?? $val ) );
						if ( $val !== '' ) {
							$opts[] = array( 'value' => $val, 'label' => $txt );
						}
					}
				}
				if ( ! empty( $opts ) ) {
					$field['options'] = $opts;
				}
			}
		}

		$placeholder = sanitize_text_field( (string) ( $gf['placeholder'] ?? '' ) );
		if ( $placeholder !== '' ) {
			$field['placeholder'] = $placeholder;
		}

		if ( $ef_type === 'html' ) {
			$field['html'] = wp_kses_post( (string) ( $gf['content'] ?? '' ) );
		}

		$max_len = $gf['maxLength'] ?? '';
		if ( $max_len !== '' && $max_len > 0 ) {
			$field['max_length'] = (int) $max_len;
		}

		return $field;
	}
}
