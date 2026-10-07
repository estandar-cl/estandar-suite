<?php
if ( ! defined('ABSPATH') ) {
    exit;
}

/**
 * =====================================================
 * Helpers de subida de archivos — Estandar Forms
 * Usa wp_upload_dir() (WordPress-safe)
 * PHP 8.1+ compatible
 * =====================================================
 */

/**
 * Obtiene la carpeta base de uploads del plugin
 * wp-content/uploads/estandar-forms/
 *
 * @return array{dir:string,url:string}|WP_Error
 */
function estandar_forms_upload_base() {

    $wp_upload = wp_upload_dir();

    // Error crítico de WordPress
    if ( ! empty($wp_upload['error']) ) {
        estandar_forms_telemetry_event('error', 'FORMS_UPLOAD_FAILURE', 'Forms upload directory lookup failed.', [
            'stage' => 'upload_dir',
        ]);
        return new WP_Error(
            'upload_dir_error',
            'Error al obtener directorio de uploads: ' . $wp_upload['error']
        );
    }

    $base_dir = trailingslashit( $wp_upload['basedir'] ) . 'estandar-forms/';
    $base_url = trailingslashit( $wp_upload['baseurl'] ) . 'estandar-forms/';

    // Crear carpeta si no existe
    if ( ! file_exists($base_dir) ) {
        if ( ! wp_mkdir_p($base_dir) ) {
            estandar_forms_telemetry_event('error', 'FORMS_UPLOAD_FAILURE', 'Forms upload directory creation failed.', [
                'stage' => 'mkdir',
            ]);
            return new WP_Error(
                'mkdir_failed',
                'No se pudo crear el directorio de uploads del formulario'
            );
        }
    }

    // Verificar permisos de escritura
    if ( ! is_writable($base_dir) ) {
        estandar_forms_telemetry_event('error', 'FORMS_UPLOAD_FAILURE', 'Forms upload directory is not writable.', [
            'stage' => 'writable',
        ]);
        return new WP_Error(
            'not_writable',
            'El directorio de uploads no tiene permisos de escritura'
        );
    }

    $index_file = $base_dir . 'index.php';
    if ( ! file_exists($index_file) ) {
        file_put_contents($index_file, "<?php\n// Silence is golden.\n");
    }

    $htaccess_file = $base_dir . '.htaccess';
    if ( ! file_exists($htaccess_file) ) {
        file_put_contents(
            $htaccess_file,
            "<FilesMatch \"\\.(php|phtml|php3|php4|php5|phar)$\">\nDeny from all\n</FilesMatch>\nOptions -Indexes\n"
        );
    }

    return [
        'dir' => $base_dir,
        'url' => $base_url,
    ];
}

/**
 * Maneja la subida segura de un archivo
 *
 * @param array $file $_FILES['campo']
 * @return array|WP_Error
 */
function estandar_forms_handle_upload( array $file ) {

    /* =====================
       VALIDACIONES BÁSICAS
    ====================== */
    if (
        empty($file['name']) ||
        empty($file['tmp_name']) ||
        ! is_uploaded_file($file['tmp_name'])
    ) {
        estandar_forms_telemetry_event('warning', 'FORMS_UPLOAD_FAILURE', 'Forms upload received no valid file.', [
            'stage' => 'no_file',
        ]);
        return new WP_Error('no_file', 'No se recibió un archivo válido');
    }

    if ( ! isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK ) {
        estandar_forms_telemetry_event('warning', 'FORMS_UPLOAD_FAILURE', 'Forms upload returned PHP upload error.', [
            'stage' => 'php_upload',
            'upload_error' => isset($file['error']) ? (int) $file['error'] : -1,
        ]);
        return new WP_Error('upload_error', 'Error en la subida del archivo');
    }

    /* =====================
       LÍMITE DE TAMAÑO (5 MB)
    ====================== */
    $max_size = (int) apply_filters('estandar_forms_upload_max_size', 5 * 1024 * 1024, $file);
    if ( ! empty($file['size']) && (int) $file['size'] > $max_size ) {
        estandar_forms_telemetry_event('warning', 'FORMS_UPLOAD_FAILURE', 'Forms upload exceeded size limit.', [
            'stage' => 'size',
        ]);
        return new WP_Error('file_too_large', 'El archivo excede el tamaño permitido');
    }

    /* =====================
       VALIDAR TIPO (WordPress-safe)
    ====================== */
    $allowed_mimes = apply_filters('estandar_forms_allowed_mimes', [
        'jpg|jpeg' => 'image/jpeg',
        'png'      => 'image/png',
        'webp'     => 'image/webp',
        'pdf'      => 'application/pdf',
    ], $file);

    $filetype = wp_check_filetype_and_ext(
        $file['tmp_name'],
        $file['name'],
        $allowed_mimes
    );

    if ( empty($filetype['ext']) || empty($filetype['type']) ) {
        estandar_forms_telemetry_event('warning', 'FORMS_UPLOAD_FAILURE', 'Forms upload rejected by file type validation.', [
            'stage' => 'file_type',
        ]);
        return new WP_Error('invalid_type', 'Tipo de archivo no permitido');
    }

    $real_mime = '';
    if ( function_exists('finfo_open') ) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ( $finfo ) {
            $real_mime = (string) finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);
        }
    }

    if ( $real_mime !== '' && ! in_array($real_mime, array_values($allowed_mimes), true) ) {
        estandar_forms_telemetry_event('warning', 'FORMS_UPLOAD_FAILURE', 'Forms upload rejected by MIME validation.', [
            'stage' => 'real_mime',
        ]);
        return new WP_Error('invalid_real_mime', 'El contenido real del archivo no está permitido');
    }

    if ( str_starts_with((string) $filetype['type'], 'image/') ) {
        $image_info = @getimagesize($file['tmp_name']);
        if ( ! is_array($image_info) ) {
            estandar_forms_telemetry_event('warning', 'FORMS_UPLOAD_FAILURE', 'Forms upload rejected invalid image.', [
                'stage' => 'image',
            ]);
            return new WP_Error('invalid_image', 'La imagen no es válida');
        }
    }

    /* =====================
       DIRECTORIO DESTINO
    ====================== */
    $upload = estandar_forms_upload_base();
    if ( is_wp_error($upload) ) {
        return $upload;
    }

    /* =====================
       NOMBRE FINAL SEGURO
    ====================== */
    $final_name = wp_unique_filename(
        $upload['dir'],
        wp_generate_uuid4() . '.' . $filetype['ext']
    );

    $destination = $upload['dir'] . $final_name;

    /* =====================
       MOVER ARCHIVO
    ====================== */
    if ( ! move_uploaded_file($file['tmp_name'], $destination) ) {
        estandar_forms_telemetry_event('error', 'FORMS_UPLOAD_FAILURE', 'Forms upload move failed.', [
            'stage' => 'move',
        ]);
        return new WP_Error(
            'move_failed',
            'No se pudo guardar el archivo en el servidor'
        );
    }

    @chmod($destination, 0644);

    /* =====================
       RESPUESTA NORMALIZADA
    ====================== */
    return [
        'path' => $destination,
        'url'  => $upload['url'] . $final_name,
        'name' => $final_name,
        'type' => $filetype['type'],
        'size' => (int) ($file['size'] ?? 0),
    ];
}

function estandar_forms_is_safe_upload_path(string $path): bool {
    $upload = estandar_forms_upload_base();
    if ( is_wp_error($upload) ) {
        return false;
    }

    $real_base = realpath($upload['dir']);
    $real_path = realpath($path);

    if ( ! $real_base || ! $real_path ) {
        return false;
    }

    return str_starts_with($real_path, $real_base . DIRECTORY_SEPARATOR) || $real_path === $real_base;
}

function estandar_forms_delete_uploaded_file(array $file): void {
    $path = isset($file['path']) && is_string($file['path']) ? $file['path'] : '';
    if ( $path === '' || ! file_exists($path) || ! estandar_forms_is_safe_upload_path($path) ) {
        return;
    }

    wp_delete_file($path);
}

function estandar_forms_delete_entry_files($data): void {
    if ( ! is_array($data) ) {
        return;
    }

    foreach ( $data as $value ) {
        if ( is_array($value) && isset($value['path']) ) {
            estandar_forms_delete_uploaded_file($value);
            continue;
        }

        if ( is_array($value) ) {
            estandar_forms_delete_entry_files($value);
        }
    }
}
