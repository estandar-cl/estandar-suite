<?php
/**
 * REGION_ROUTING_V1 — Lista de localidades de La Araucanía (Chile) con su zona
 * de routing para el campo de ciudad del form (texto libre y select).
 *
 * Cada entrada: `name` (localidad/comuna, sin acentos para matching robusto),
 * `zone` (temuco | villarrica). La zona villarrica = Villarrica + localidades
 * dentro de un radio de ~40 km de Villarrica (sucursal propia, aún sin definir
 * proyecto → por ahora deriva al proyecto general). La zona temuco rutea al
 * proyecto de Temuco.
 *
 * Fuente de la clasificación: comunas de la Región de La Araucanía (Cautín +
 * Malleco) + localidades/pueblos principales. Confirmado con el cliente 31/08.
 *
 * @package Estandar\Capture
 */

return array(
	// ── Zona VILLARRICA (Villarrica ± 40 km) → por ahora proyecto general ──
	array( 'name' => 'Villarrica',          'zone' => 'villarrica' ),
	array( 'name' => 'Pucón',               'zone' => 'villarrica' ),
	array( 'name' => 'Pucon',               'zone' => 'villarrica' ),
	array( 'name' => 'Licán Ray',           'zone' => 'villarrica' ),
	array( 'name' => 'Lican Ray',           'zone' => 'villarrica' ),
	array( 'name' => 'Coñaripe',            'zone' => 'villarrica' ),
	array( 'name' => 'Conaripe',            'zone' => 'villarrica' ),
	array( 'name' => 'Caburgua',            'zone' => 'villarrica' ),
	array( 'name' => 'Huife',               'zone' => 'villarrica' ),
	array( 'name' => 'Cunco',               'zone' => 'villarrica' ),
	array( 'name' => 'Curarrehue',          'zone' => 'villarrica' ),
	array( 'name' => 'Hualacura',           'zone' => 'villarrica' ),
	array( 'name' => 'Molco',               'zone' => 'villarrica' ),
	array( 'name' => 'Quilche',             'zone' => 'villarrica' ),

	// ── Zona TEMUCO (La Araucanía fuera del radio de Villarrica) ───────────
	// Cautín
	array( 'name' => 'Temuco',              'zone' => 'temuco' ),
	array( 'name' => 'Padre Las Casas',     'zone' => 'temuco' ),
	array( 'name' => 'Labranza',            'zone' => 'temuco' ),
	array( 'name' => 'Huichahue',           'zone' => 'temuco' ),
	array( 'name' => 'Cajón',               'zone' => 'temuco' ),
	array( 'name' => 'Cajon',               'zone' => 'temuco' ),
	array( 'name' => 'Nueva Imperial',      'zone' => 'temuco' ),
	array( 'name' => 'Imperial',            'zone' => 'temuco' ),
	array( 'name' => 'Carahue',             'zone' => 'temuco' ),
	array( 'name' => 'Nehuentúe',           'zone' => 'temuco' ),
	array( 'name' => 'Nehuentue',           'zone' => 'temuco' ),
	array( 'name' => 'Puerto Saavedra',     'zone' => 'temuco' ),
	array( 'name' => 'Saavedra',            'zone' => 'temuco' ),
	array( 'name' => 'Teodoro Schmidt',     'zone' => 'temuco' ),
	array( 'name' => 'Freire',              'zone' => 'temuco' ),
	array( 'name' => 'Quepe',               'zone' => 'temuco' ),
	array( 'name' => 'Pitrufquén',          'zone' => 'temuco' ),
	array( 'name' => 'Pitrufquen',          'zone' => 'temuco' ),
	array( 'name' => 'Gorbea',              'zone' => 'temuco' ),
	array( 'name' => 'Loncoche',            'zone' => 'temuco' ),
	array( 'name' => 'Toltén',              'zone' => 'temuco' ),
	array( 'name' => 'Tolten',              'zone' => 'temuco' ),
	array( 'name' => 'Hualpín',             'zone' => 'temuco' ),
	array( 'name' => 'Hualpin',             'zone' => 'temuco' ),
	array( 'name' => 'Perquenco',           'zone' => 'temuco' ),
	array( 'name' => 'Lautaro',             'zone' => 'temuco' ),
	array( 'name' => 'Galvarino',           'zone' => 'temuco' ),
	array( 'name' => 'Vilcún',              'zone' => 'temuco' ),
	array( 'name' => 'Vilcun',              'zone' => 'temuco' ),
	array( 'name' => 'Cholchol',            'zone' => 'temuco' ),
	array( 'name' => 'Melipeuco',           'zone' => 'temuco' ),
	// Malleco
	array( 'name' => 'Angol',               'zone' => 'temuco' ),
	array( 'name' => 'Renaico',             'zone' => 'temuco' ),
	array( 'name' => 'Collipulli',          'zone' => 'temuco' ),
	array( 'name' => 'Ercilla',             'zone' => 'temuco' ),
	array( 'name' => 'Los Sauces',          'zone' => 'temuco' ),
	array( 'name' => 'Lumaco',              'zone' => 'temuco' ),
	array( 'name' => 'Purén',               'zone' => 'temuco' ),
	array( 'name' => 'Puren',               'zone' => 'temuco' ),
	array( 'name' => 'Traiguén',            'zone' => 'temuco' ),
	array( 'name' => 'Traiguen',            'zone' => 'temuco' ),
	array( 'name' => 'Victoria',            'zone' => 'temuco' ),
	array( 'name' => 'Curacautín',          'zone' => 'temuco' ),
	array( 'name' => 'Curacautin',          'zone' => 'temuco' ),
	array( 'name' => 'Malalcahuello',       'zone' => 'temuco' ),
	array( 'name' => 'Manzanar',            'zone' => 'temuco' ),
	array( 'name' => 'Lonquimay',           'zone' => 'temuco' ),
);
