<?php
if ( ! defined('ABSPATH') ) exit;

/**
 * Gravity Forms → Estandar Forms import adapter.
 *
 * Modular pipeline: detect → parse → normalize → map → report.
 * Reusable for any GF form JSON export. Not coupled to a specific form.
 *
 * Usage:
 *   $importer = new Estandar_Forms_GF_Importer( $gf_json );
 *   $report   = $importer->analyze();
 *   if ( $report['can_import'] ) {
 *       $form_id = $importer->import();
 *   }
 *
 * @since 13.0.156
 */
class Estandar_Forms_GF_Importer {

    private array $source;
    private array $report = [];
    private array $warnings = [];
    private array $lost_fields = [];

    /**
     * GF → EF field type map.
     */
    private const TYPE_MAP = [
        'text'       => 'text',
        'textarea'   => 'textarea',
        'email'      => 'email',
        'phone'      => 'tel',
        'select'     => 'select',
        'radio'      => 'radio',
        'checkbox'   => 'checkbox',
        'number'     => 'number',
        'date'       => 'date',
        'fileupload' => 'file',
        'hidden'     => 'hidden',
        'website'    => 'text',
        'html'       => 'custom',
        'list'       => 'select',
        'multiselect'=> 'select',
        'name'       => 'name',   // composite → split
        'address'    => 'address', // composite → split
        'section'    => 'section', // no EF equivalent
        'page'       => 'page',    // no EF equivalent
        'captcha'    => null,      // not imported
        'post_title' => 'text',
        'post_content' => 'textarea',
        'post_excerpt' => 'textarea',
        'post_tags'  => 'text',
        'post_category' => 'select',
        'post_image' => 'file',
        'post_custom_field' => 'text',
    ];

    /**
     * Fields that should be split into multiple EF fields.
     */
    private const SPLIT_FIELDS = [
        'name' => [
            ['name' => 'nombre',   'label' => 'Nombre',    'required' => true],
            ['name' => 'apellido', 'label' => 'Apellido',   'required' => true],
        ],
    ];

    /**
     * @param string|array $json GF form JSON string or array.
     */
    public function __construct( $json ) {
        if ( is_string( $json ) ) {
            $json = json_decode( $json, true );
        }
        if ( ! is_array( $json ) ) {
            $this->report['error'] = 'JSON inválido.';
            return;
        }
        $this->source = $json;
    }

    /* ── DETECTION ─────────────────────────────────── */

    public function is_gravity_forms(): bool {
        if ( empty( $this->source ) ) return false;

        // GF export has 'version', 'id', 'title', 'fields' at top level
        return isset( $this->source['fields'] )
            && is_array( $this->source['fields'] )
            && isset( $this->source['id'] )
            && isset( $this->source['title'] );
    }

    /* ── ANALYSIS ──────────────────────────────────── */

    public function analyze(): array {
        if ( ! $this->is_gravity_forms() ) {
            return [ 'can_import' => false, 'error' => 'El archivo no es un formulario Gravity Forms válido.' ];
        }

        $fields = (array) ( $this->source['fields'] ?? [] );
        $total  = count( $fields );
        $mapped = 0;
        $lost   = 0;
        $split  = 0;
        $unsupported_types = [];

        foreach ( $fields as $f ) {
            $gf_type = sanitize_key( (string) ( $f['type'] ?? '' ) );
            $ef_type = self::TYPE_MAP[ $gf_type ] ?? null;

            if ( $ef_type === null ) {
                $lost++;
                $unsupported_types[ $gf_type ] = ( $unsupported_types[ $gf_type ] ?? 0 ) + 1;
                $this->lost_fields[] = [
                    'gf_id'   => (int) ( $f['id'] ?? 0 ),
                    'gf_type' => $gf_type,
                    'label'   => sanitize_text_field( (string) ( $f['label'] ?? $f['adminLabel'] ?? '' ) ),
                    'reason'  => 'Tipo no soportado en Estandar Forms.',
                ];
            } elseif ( $ef_type === 'section' || $ef_type === 'page' ) {
                $lost++;
                $this->lost_fields[] = [
                    'gf_id'   => (int) ( $f['id'] ?? 0 ),
                    'gf_type' => $gf_type,
                    'label'   => sanitize_text_field( (string) ( $f['label'] ?? '' ) ),
                    'reason'  => 'Estandar Forms no tiene equivalentes de sección/página. Agrupado en el layout.',
                ];
            } elseif ( isset( self::SPLIT_FIELDS[ $gf_type ] ) ) {
                $split += count( self::SPLIT_FIELDS[ $gf_type ] );
            } else {
                $mapped++;
            }
        }

        $mapped_total = $mapped + $split;
        $compatibility = $total > 0 ? round( $mapped_total / $total * 100, 1 ) : 0;

        $this->report = [
            'can_import'           => $compatibility >= 70,
            'source_title'         => sanitize_text_field( (string) ( $this->source['title'] ?? 'Sin título' ) ),
            'source_id'            => (int) ( $this->source['id'] ?? 0 ),
            'total_fields'         => $total,
            'mapped_fields'        => $mapped,
            'split_fields'         => $split,
            'lost_fields'          => $lost,
            'compatibility_pct'    => $compatibility,
            'unsupported_types'    => $unsupported_types,
            'lost_fields_detail'   => $this->lost_fields,
            'warnings'             => $this->warnings,
        ];

        return $this->report;
    }

    /* ── IMPORT ────────────────────────────────────── */

    public function import( array $options = [] ): array {
        if ( ! $this->is_gravity_forms() ) {
            return [ 'error' => 'No es un formulario Gravity Forms válido.' ];
        }

        $this->analyze();

        $name         = sanitize_text_field( (string) ( $this->source['title'] ?? 'Formulario importado' ) );
        $slug         = sanitize_title( $name );
        $slug         = $this->unique_slug( $slug );
        $success_msg  = sanitize_text_field( (string) ( $options['success_message'] ?? 'Gracias. Hemos recibido tu información correctamente.' ) );
        $submit_label = sanitize_text_field( (string) ( $this->source['button']['text'] ?? 'Enviar' ) );

        $fields = $this->convert_fields();

        global $wpdb;
        $table = $wpdb->prefix . 'estandar_forms';

        $inserted = $wpdb->insert( $table, [
            'name'              => $name,
            'slug'              => $slug,
            'form_type'         => sanitize_key( (string) ( $options['form_type'] ?? 'lead' ) ),
            'form_context'      => sanitize_key( (string) ( $options['form_context'] ?? 'generic' ) ),
            'fields_json'       => wp_json_encode( $fields ),
            'success_message'   => $success_msg,
            'submit_label'      => $submit_label,
            'context_schema'    => wp_json_encode( $options['context_schema'] ?? [] ),
            'incidents_config'  => wp_json_encode( $options['incidents_config'] ?? null ),
            'actions_json'      => wp_json_encode( $options['actions_json'] ?? null ),
            'notify_emails'     => sanitize_text_field( (string) ( $options['notify_emails'] ?? '' ) ),
            'form_country'      => 'CL',
            'store_entries'     => 1,
            'send_email'        => 1,
        ], [ '%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%d','%d' ] );

        if ( ! $inserted ) {
            return [ 'error' => 'No se pudo insertar el formulario en la base de datos.' ];
        }

        $form_id = (int) $wpdb->insert_id;

        if ( class_exists( 'Estandar_Forms_Shortcode' ) ) {
            Estandar_Forms_Shortcode::clear_form_cache( $form_id );
        }

        return [
            'form_id'    => $form_id,
            'slug'       => $slug,
            'name'       => $name,
            'shortcode'  => '[estandar_form id="' . $form_id . '"]',
            'report'     => $this->report,
        ];
    }

    /* ── FIELD CONVERSION ──────────────────────────── */

    private function convert_fields(): array {
        $gf_fields = (array) ( $this->source['fields'] ?? [] );
        $ef_fields = [];

        foreach ( $gf_fields as $f ) {
            if ( empty( $f['type'] ) ) continue;
            if ( (string) ( $f['visibility'] ?? 'visible' ) !== 'visible' ) continue;

            $gf_type = sanitize_key( (string) $f['type'] );

            // Split composite fields
            if ( isset( self::SPLIT_FIELDS[ $gf_type ] ) ) {
                foreach ( self::SPLIT_FIELDS[ $gf_type ] as $split_def ) {
                    $ef_fields[] = $this->build_split_field( $f, $split_def );
                }
                continue;
            }

            $ef_type = self::TYPE_MAP[ $gf_type ] ?? null;
            if ( $ef_type === null || in_array( $ef_type, ['section', 'page'], true ) ) {
                continue;
            }

            $ef_field = $this->build_ef_field( $f, $ef_type );
            if ( $ef_field ) {
                $ef_fields[] = $ef_field;
            }
        }

        return $ef_fields;
    }

    private function build_ef_field( array $gf, string $ef_type ): ?array {
        $label    = sanitize_text_field( (string) ( $gf['label'] ?? $gf['adminLabel'] ?? '' ) );
        $admin    = sanitize_key( (string) ( $gf['adminLabel'] ?? '' ) );
        $name     = $admin !== '' ? $admin : sanitize_title( $label );
        $name     = $name !== '' ? $name : $ef_type . '_' . (int) ( $gf['id'] ?? 0 );
        $required = ! empty( $gf['isRequired'] );
        $hint     = sanitize_text_field( (string) ( $gf['description'] ?? '' ) );
        $css      = sanitize_html_class( (string) ( $gf['cssClass'] ?? '' ) );

        $field = [
            'type'     => $ef_type,
            'name'     => $name,
            'label'    => $label !== '' ? $label : $name,
            'required' => $required,
        ];

        if ( $hint !== '' ) {
            $field['hint'] = $hint;
        }
        if ( $css !== '' ) {
            $field['css_class'] = $css;
        }

        // Choices/options
        if ( in_array( $ef_type, ['select', 'radio', 'checkbox'], true ) ) {
            $choices = $gf['choices'] ?? [];
            if ( is_array( $choices ) ) {
                $opts = [];
                foreach ( $choices as $c ) {
                    if ( is_array( $c ) ) {
                        $val = sanitize_text_field( (string) ( $c['value'] ?? '' ) );
                        $txt = sanitize_text_field( (string) ( $c['text'] ?? $val ) );
                        if ( $val !== '' ) {
                            $opts[] = [ 'value' => $val, 'label' => $txt ];
                        }
                    }
                }
                if ( ! empty( $opts ) ) {
                    $field['options'] = $opts;
                }
            }
        }

        // Placeholder
        $placeholder = sanitize_text_field( (string) ( $gf['placeholder'] ?? '' ) );
        if ( $placeholder !== '' ) {
            $field['placeholder'] = $placeholder;
        }

        // Conditional logic
        $cond = $gf['conditionalLogic'] ?? null;
        if ( is_array( $cond ) && ! empty( $cond['rules'] ) ) {
            $field['conditional_logic'] = $this->convert_conditional_logic( $cond );
        }

        // Custom HTML
        if ( $ef_type === 'custom' ) {
            $field['html'] = wp_kses_post( (string) ( $gf['content'] ?? '' ) );
        }

        // File upload
        if ( $ef_type === 'file' ) {
            $max_files = (int) ( $gf['maxFiles'] ?? 1 );
            $field['max_size_mb'] = 10;
            if ( $max_files > 1 ) {
                $field['multiple'] = true;
                $field['max_files'] = $max_files;
            }
        }

        // Max length
        $max_len = $gf['maxLength'] ?? '';
        if ( $max_len !== '' && $max_len > 0 ) {
            $field['maxlength'] = (int) $max_len;
        }

        return $field;
    }

    private function build_split_field( array $gf, array $split_def ): array {
        $name     = sanitize_key( (string) $split_def['name'] );
        $label    = sanitize_text_field( (string) $split_def['label'] );
        $required = $split_def['required'] ?? ! empty( $gf['isRequired'] );
        $hint     = sanitize_text_field( (string) ( $gf['description'] ?? '' ) );

        $field = [
            'type'     => 'text',
            'name'     => $name,
            'label'    => $label,
            'required' => $required ? true : false,
        ];

        if ( $hint !== '' ) {
            $field['hint'] = $hint;
        }

        $cond = $gf['conditionalLogic'] ?? null;
        if ( is_array( $cond ) && ! empty( $cond['rules'] ) ) {
            $field['conditional_logic'] = $this->convert_conditional_logic( $cond );
        }

        return $field;
    }

    private function convert_conditional_logic( array $cond ): array {
        $rules = [];
        $logic_type = strtolower( (string) ( $cond['logicType'] ?? 'all' ) );
        $is_any     = ( $logic_type === 'any' );

        foreach ( (array) ( $cond['rules'] ?? [] ) as $rule ) {
            $gf_op    = sanitize_key( (string) ( $rule['operator'] ?? 'is' ) );
            $field_id = (int) ( $rule['fieldId'] ?? 0 );
            $value    = sanitize_text_field( (string) ( $rule['value'] ?? '' ) );

            $ef_op = match ( $gf_op ) {
                'is', 'isnot'           => $gf_op === 'is' ? 'equals' : 'not_equals',
                'contains', 'notcontains' => $gf_op === 'contains' ? 'contains' : 'not_contains',
                'greater_than'          => 'greater_than',
                'less_than'             => 'less_than',
                'starts_with', 'ends_with' => 'equals',
                default                 => 'equals',
            };

            $field_name = $this->resolve_field_name( $field_id );

            $rules[] = [
                'field'    => $field_name,
                'operator' => $ef_op,
                'value'    => $value,
            ];
        }

        // EF's normalize function expects a single rule object {field, operator, value}.
        // Return the first rule directly for single-rule conditionals.
        if ( count( $rules ) === 1 ) {
            return $rules[0];
        }

        return $rules;
    }

    private function resolve_field_name( int $gf_id ): string {
        static $map = null;
        if ( $map === null ) {
            $map = [];
            foreach ( (array) ( $this->source['fields'] ?? [] ) as $f ) {
                $gf_type = sanitize_key( (string) ( $f['type'] ?? '' ) );
                $id      = (int) ( $f['id'] ?? 0 );
                $admin   = sanitize_key( (string) ( $f['adminLabel'] ?? '' ) );
                $label   = sanitize_title( (string) ( $f['label'] ?? '' ) );

                if ( isset( self::SPLIT_FIELDS[ $gf_type ] ) ) {
                    // Split fields get their own names
                    foreach ( self::SPLIT_FIELDS[ $gf_type ] as $i => $sdef ) {
                        if ( $i === 0 ) {
                            $map[ $id ] = sanitize_key( $sdef['name'] );
                        }
                        // Additional split fields not referenceable from GF conditions
                    }
                } else {
                    $name = $admin !== '' ? $admin : $label;
                    $map[ $id ] = sanitize_title( $name ) ?: ( 'field_' . $id );
                }
            }
        }
        return $map[ $gf_id ] ?? ( 'field_' . $gf_id );
    }

    /* ── UTILS ─────────────────────────────────────── */

    private function unique_slug( string $slug ): string {
        global $wpdb;
        $base = $slug;
        $i    = 1;
        $table = $wpdb->prefix . 'estandar_forms';
        while ( $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE slug = %s", $slug
        ) ) ) {
            $slug = $base . '-' . $i;
            $i++;
        }
        return $slug;
    }
}
