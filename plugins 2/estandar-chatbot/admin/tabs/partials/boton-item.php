<?php
$index = isset($i) ? $i : '__INDEX__';
$btn = $btn ?? [];
$is_sub = strpos( (string) $index, '_c_' ) !== false;
?>
<div class="ecb-boton-item ecb-card" data-boton-item="1" style="margin-bottom:12px;<?php echo $is_sub ? 'margin-left:18px;' : ''; ?>">
	<div style="display:flex;justify-content:space-between;align-items:center;gap:8px;">
		<div style="display:flex;align-items:center;gap:8px;">
			<span class="ecb-drag-handle" aria-hidden="true">⋮⋮</span>
			<strong><?php echo $is_sub ? 'Sub-botón' : 'Botón'; ?></strong>
		</div>
		<button type="button" class="button-link-delete ecb-remove-boton" aria-label="Eliminar botón">Eliminar</button>
	</div>

	<div class="ecb-card-grid" style="grid-template-columns:100px minmax(220px,1fr) minmax(180px,220px);margin-top:8px;">
		<div>
			<label style="display:block;font-size:12px;color:#6e6e73;margin-bottom:4px;">Icono</label>
			<input class="ecb-boton-icono" type="text" name="chatbot_botones_iniciales[<?php echo $index; ?>][icono]" value="<?php echo esc_attr($btn['icono'] ?? ''); ?>" maxlength="4" placeholder="💬" style="width:100%;">
		</div>
		<div>
			<label style="display:block;font-size:12px;color:#6e6e73;margin-bottom:4px;">Texto visible</label>
			<input class="ecb-boton-texto" type="text" name="chatbot_botones_iniciales[<?php echo $index; ?>][texto]" value="<?php echo esc_attr($btn['texto'] ?? ''); ?>" placeholder="Ej: Quiero cotizar" style="width:100%;">
		</div>
		<div>
			<label style="display:block;font-size:12px;color:#6e6e73;margin-bottom:4px;">Tipo de acción</label>
			<select class="ecb-boton-tipo" name="chatbot_botones_iniciales[<?php echo $index; ?>][tipo]" style="width:100%;">
				<option value="ia" <?php selected($btn['tipo'] ?? '', 'ia'); ?>>Enviar mensaje al bot (IA)</option>
				<option value="info" <?php selected($btn['tipo'] ?? '', 'info'); ?>>Mostrar información fija</option>
				<option value="link" <?php selected($btn['tipo'] ?? '', 'link'); ?>>Abrir enlace</option>
				<option value="human_escalation" <?php selected($btn['tipo'] ?? '', 'human_escalation'); ?>>Hablar con humano</option>
				<option value="submenu" <?php selected($btn['tipo'] ?? '', 'submenu'); ?>>Submenú (avanzado)</option>
			</select>
		</div>
	</div>

	<p style="margin-top:8px;">
		<label style="display:block;font-size:12px;color:#6e6e73;margin-bottom:4px;">Mensaje, URL o acción</label>
		<textarea class="ecb-boton-payload" name="chatbot_botones_iniciales[<?php echo $index; ?>][payload]" rows="2" style="width:100%;"><?php echo esc_textarea($btn['payload'] ?? ''); ?></textarea>
		<span class="description ecb-boton-help" style="display:block;margin-top:4px;"></span>
	</p>

	<?php if ( ($btn['tipo'] ?? '') === 'submenu' ) : ?>
		<p class="description" style="margin-top:-2px;">Opción avanzada para desarrolladores. Si no necesitas subniveles, usa acciones simples para mantener mejor conversión.</p>
	<?php endif; ?>

	<input class="ecb-boton-id" type="hidden" name="chatbot_botones_iniciales[<?php echo $index; ?>][id]" value="<?php echo esc_attr($btn['id'] ?? ''); ?>">

	<?php if ( ($btn['tipo'] ?? '') === 'submenu' && ! empty($btn['children']) ) : ?>
		<div class="ecb-subbotones" style="margin-top:8px;">
			<strong style="display:block;margin-bottom:8px;">Sub-botones existentes</strong>
			<?php 
			$__depth = substr_count((string) $index, '_c_');
			foreach ( $btn['children'] as $j => $child ) :

				if ( $__depth >= 5 ) break;

				$__parent_btn = $btn;
				$__parent_i   = $index;

				$btn   = $child;
				$i     = $index . '_c_' . $j;
				$index = $i;

				include __FILE__;

				$btn   = $__parent_btn;
				$i     = $__parent_i;
				$index = $__parent_i;

			endforeach; ?>
			<p class="description" style="margin:6px 0 0;">Para crear nuevos sub-botones, edita este botón como tipo submenu y agrega hijos desde JSON avanzado (próxima iteración: editor visual).</p>
		</div>
	<?php endif; ?>
</div>
