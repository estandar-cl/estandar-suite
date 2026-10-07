<?php
/**
 * Estandar Suite — Capture · Maquetador visual de campos (para humanos).
 * Reemplaza el JSON crudo por un editor de campos con tipos, etiquetas,
 * obligatoriedad y opciones. Serializa a config_json al guardar.
 * Variables: $feature, $config (array), $fp_singular.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$fields = isset( $config['fields_json'] ) && is_array( $config['fields_json'] ) ? $config['fields_json'] : array();
$field_types = array(
	'text'      => 'Texto',
	'textarea'  => 'Texto largo',
	'email'     => 'Email',
	'phone'     => 'Teléfono',
	'number'    => 'Número',
	'date'      => 'Fecha',
	'time'      => 'Hora',
	'select'    => 'Lista de opciones',
	'radio'     => 'Opción única',
	'checkbox'  => 'Casillas de verificación',
	'nps'       => 'NPS (0-10)',
);
?>
<div style="max-width:820px;">
	<h5>Campos del <?php echo esc_html( $fp_singular ); ?></h5>
	<p class="description">Agrega los campos que verá la persona. El orden de abajo hacia arriba es el orden de aparición.</p>
	<table class="widefat striped" id="es-fields-<?php echo esc_attr( $feature ); ?>">
		<thead><tr><th style="width:16%;">Tipo</th><th>Etiqueta</th><th style="width:9%;">Oblig.</th><th style="width:10%;">Ancho</th><th>Mostrar si (campo = valor)</th><th>Opciones</th><th style="width:14%;"></th></tr></thead>
		<tbody></tbody>
	</table>
	<p style="margin-top:8px;">
		<button type="button" class="button" id="es-add-field-<?php echo esc_attr( $feature ); ?>" style="font-weight:600;">+ Agregar campo</button>
	</p>
</div>
<script>
(function () {
	var fid = <?php echo wp_json_encode( $feature ); ?>;
	var FIELDS = <?php echo wp_json_encode( $fields, JSON_UNESCAPED_UNICODE ); ?>;
	var TYPES = <?php echo wp_json_encode( $field_types, JSON_UNESCAPED_UNICODE ); ?>;
	var $ = function (id) { return document.getElementById(id); };
	var tbody = $('es-fields-' + fid).querySelector('tbody');
	var cfgInput = $('es-config-' + fid);
	var optionsTypes = { select: 1, radio: 1, checkbox: 1 };

	function row(i, f) {
		var typeOptions = Object.keys(TYPES).map(function (t) {
			return '<option value="' + t + '"' + (f.type === t ? ' selected' : '') + '>' + TYPES[t] + '</option>';
		}).join('');
		// Ancho de columna: legacy 1-4 (25/50/75/100 %) o % directo.
		var cw = f.column_width == null ? 4 : f.column_width;
		var cwPct = (cw >= 1 && cw <= 4) ? Math.round(100 * cw / 4) : parseFloat(cw);
		var widthOptions = [100, 75, 50, 25].map(function (p) {
			return '<option value="' + p + '"' + (cwPct === p ? ' selected' : '') + '>' + p + '%</option>';
		}).join('');
		var optionsArea = optionsTypes[f.type]
			? '<textarea class="es-f-options" rows="2" placeholder="Una opción por línea">' + ((f.options || []).join('\n')) + '</textarea>'
			: '';
		var showWhen = f.show_when || {};
		return '<tr data-i="' + i + '">'
			+ '<td><select class="es-f-type">' + typeOptions + '</select></td>'
			+ '<td><input class="es-f-label regular-text" type="text" placeholder="Ej: Nombre completo" value="' + ((f.label || '').replace(/"/g, '&quot;')) + '"></td>'
			+ '<td><input class="es-f-required" type="checkbox"' + (f.required ? ' checked' : '') + '></td>'
			+ '<td><select class="es-f-width">' + widthOptions + '</select></td>'
			+ '<td><input class="es-f-show-field regular-text" type="text" placeholder="campo" style="width:90px;" value="' + ((showWhen.field || '').replace(/"/g, '&quot;')) + '"> = <input class="es-f-show-value regular-text" type="text" placeholder="valor" style="width:90px;" value="' + ((showWhen.value || '').replace(/"/g, '&quot;')) + '"></td>'
			+ '<td>' + optionsArea + '</td>'
			+ '<td><button type="button" class="button es-f-up" title="Subir">↑</button> <button type="button" class="button es-f-down" title="Bajar">↓</button> <button type="button" class="button-link-delete es-f-del" title="Quitar campo">✕</button></td>'
			+ '</tr>';
	}

	function render() {
		tbody.innerHTML = FIELDS.map(row).join('');
		Array.prototype.forEach.call(tbody.querySelectorAll('tr'), function (tr) {
			var i = parseInt(tr.getAttribute('data-i'), 10);
			tr.querySelector('.es-f-type').addEventListener('change', function () { render(); });
			tr.querySelector('.es-f-up').addEventListener('click', function () { move(i, -1); });
			tr.querySelector('.es-f-down').addEventListener('click', function () { move(i, 1); });
			tr.querySelector('.es-f-del').addEventListener('click', function () { FIELDS.splice(i, 1); render(); });
		});
	}

	function move(i, d) {
		var j = i + d;
		if (j < 0 || j >= FIELDS.length) { return; }
		var t = FIELDS[i]; FIELDS[i] = FIELDS[j]; FIELDS[j] = t;
		render();
	}

	$('es-add-field-' + fid).addEventListener('click', function () {
		FIELDS.push({ name: '', label: '', type: 'text', required: false, options: [] });
		render();
	});

	function slugify(s) {
		return (s || '').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '');
	}

	function collect() {
		var out = [];
		Array.prototype.forEach.call(tbody.querySelectorAll('tr'), function (tr) {
			var label = tr.querySelector('.es-f-label').value.trim();
			var type = tr.querySelector('.es-f-type').value;
			var f = { name: slugify(label), label: label, type: type, required: tr.querySelector('.es-f-required').checked, options: [] };
			f.column_width = parseInt(tr.querySelector('.es-f-width').value, 10) || 4;
			var sf = tr.querySelector('.es-f-show-field').value.trim();
			var sv = tr.querySelector('.es-f-show-value').value.trim();
			if (sf && sv) { f.show_when = { field: slugify(sf), value: sv }; }
			var ta = tr.querySelector('.es-f-options');
			if (ta) {
				f.options = ta.value.split('\n').map(function (s) { return s.trim(); }).filter(Boolean);
			}
			out.push(f);
		});
		return out;
	}

	cfgInput.form.addEventListener('submit', function () {
		var cfg = {};
		try { cfg = JSON.parse(cfgInput.value || '{}') || {}; } catch (e) { cfg = {}; }
		cfg.fields_json = collect();
		// Capability-driven settings (data-key / data-nest).
		Array.prototype.forEach.call(document.querySelectorAll('.es-cfg-field'), function (el) {
			var key = el.getAttribute('data-key') || '';
			if (!key) { return; }
			var value = el.type === 'checkbox' ? (el.checked ? '1' : '0') : el.value;
			var nest = el.getAttribute('data-nest') || '';
			if (nest) { cfg[nest] = cfg[nest] || {}; cfg[nest][key] = value; }
			else { cfg[key] = value; }
		});
		cfgInput.value = JSON.stringify(cfg);
	});

	render();
})();
</script>
