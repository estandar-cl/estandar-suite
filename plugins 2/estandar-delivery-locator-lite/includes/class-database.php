<?php
/**
 * Tabla simple de pedidos con ubicación.
 *
 * @package Estandar_Delivery_Locator_Lite
 */

defined( 'ABSPATH' ) || exit;

class Estandar_Delivery_Locator_Database {

	/**
	 * Nombre de tabla sin prefijo.
	 */
	const TABLE_NAME = 'estandar_delivery_locator';

	/**
	 * Crea o actualiza la tabla en activación / migración.
	 */
	public static function create_table() {
		global $wpdb;

		$table   = self::table_name();
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			name varchar(190) NOT NULL DEFAULT '',
			phone varchar(100) NOT NULL DEFAULT '',
			address text NOT NULL,
			lat decimal(10,7) NOT NULL,
			lng decimal(10,7) NOT NULL,
			email_sent tinyint(1) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			KEY created_at (created_at)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Nombre completo con prefijo wp_.
	 */
	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_NAME;
	}

	/**
	 * Inserta un registro.
	 *
	 * @param array $data name, phone, address, lat, lng.
	 * @return int|false ID insertado o false.
	 */
	public static function insert( $data ) {
		global $wpdb;

		$table = self::table_name();
		$now   = current_time( 'mysql', true );

		$inserted = $wpdb->insert(
			$table,
			array(
				'name'        => $data['name'],
				'phone'       => $data['phone'],
				'address'     => $data['address'],
				'lat'         => $data['lat'],
				'lng'         => $data['lng'],
				'email_sent'  => 0,
				'created_at'  => $now,
			),
			array( '%s', '%s', '%s', '%f', '%f', '%d', '%s' )
		);

		if ( ! $inserted ) {
			return false;
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Marca correo de notificación como enviado.
	 *
	 * @param int $id ID pedido.
	 * @return bool
	 */
	public static function mark_email_sent( $id ) {
		global $wpdb;

		$table = self::table_name();
		$id    = absint( $id );

		$updated = $wpdb->update(
			$table,
			array( 'email_sent' => 1 ),
			array( 'id' => $id ),
			array( '%d' ),
			array( '%d' )
		);

		return false !== $updated;
	}

	/**
	 * Lista todos los pedidos (admin), más recientes primero.
	 *
	 * @param int $limit Límite opcional.
	 * @return array
	 */
	public static function get_all( $limit = 500 ) {
		global $wpdb;

		$table = self::table_name();
		$limit = absint( $limit );
		$sql   = $wpdb->prepare(
			"SELECT id, name, phone, address, lat, lng, email_sent, created_at FROM {$table} ORDER BY id DESC LIMIT %d",
			$limit
		);

		return $wpdb->get_results( $sql, ARRAY_A );
	}

	/**
	 * Obtiene un pedido por ID.
	 *
	 * @param int $id ID.
	 * @return array|null
	 */
	public static function get_by_id( $id ) {
		global $wpdb;

		$table = self::table_name();
		$id    = absint( $id );

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, name, phone, address, lat, lng, email_sent, created_at FROM {$table} WHERE id = %d",
				$id
			),
			ARRAY_A
		);
	}
}
