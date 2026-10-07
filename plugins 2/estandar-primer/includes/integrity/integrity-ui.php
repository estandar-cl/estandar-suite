<?php
/**
 * INTEGRITY_AUTOFIX_V1 — shared integrity findings component.
 *
 * SINGLE renderer for integrity findings, consumed by BOTH pages:
 *  - Estado del ecosistema  → variant 'full'    (canonical Integrity Auditor UI)
 *  - Configuración compartida → variant 'summary' (compact rows, same component)
 *
 * One implementation: cards, buttons and the AJAX workflow live only here.
 * Buttons derive from each rule's capabilities() — the pages never know how
 * a rule is repaired. Vanilla JS + admin-ajax; no page reload.
 *
 * @package estandar-core-mu
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'estandar_core_integrity_findings' ) ) {
	/**
	 * Shared findings builder — one evaluation reused by every variant.
	 *
	 * @return array{cards:array<int,array{rule:Estandar_Integrity_Rule,check:Estandar_Integrity_Audit_Result}>,fixable:int,manual:int}
	 */
	function estandar_core_integrity_findings(): array {
		$out = array( 'cards' => array(), 'fixable' => 0, 'manual' => 0 );
		if ( ! class_exists( 'Estandar_Integrity_Registry' ) ) {
			return $out;
		}
		foreach ( Estandar_Integrity_Registry::build()->all() as $rule ) {
			try {
				$check = $rule->check();
			} catch ( Throwable $e ) {
				$check = Estandar_Integrity_Audit_Result::fail( 'No se pudo evaluar: ' . $e->getMessage() );
			}
			if ( $check->passing() ) {
				continue; // stale summary entry — already compliant, no card
			}
			if ( $rule->can_auto_fix() ) {
				$out['fixable']++;
			} else {
				$out['manual']++;
			}
			$out['cards'][] = array( 'rule' => $rule, 'check' => $check );
		}
		return $out;
	}
}

if ( ! function_exists( 'estandar_core_integrity_severity_chip' ) ) {
	function estandar_core_integrity_severity_chip( string $level ): string {
		$map = array(
			'HIGH'   => array( 'background:#dc2626;color:#fff;', 'Alta' ),
			'MEDIUM' => array( 'background:#b7791f;color:#fff;', 'Media' ),
			'LOW'    => array( 'background:#e5e7eb;color:#374151;', 'Baja' ),
		);
		$row = $map[ $level ] ?? $map['LOW'];
		return '<span style="flex-shrink:0;padding:1px 7px;border-radius:3px;font-size:10px;font-weight:700;letter-spacing:.04em;' . $row[0] . '">' . $row[1] . '</span>';
	}
}

if ( ! function_exists( 'estandar_core_render_integrity_cards' ) ) {
	/**
	 * Render the shared integrity component.
	 *
	 * @param array $args {
	 *     @type string $variant 'full' (canonical console) or 'summary' (compact rows).
	 *     @type int    $limit   Max findings in summary variant (0 = all).
	 * }
	 */
	function estandar_core_render_integrity_cards( array $args = array() ): void {
		if ( ! current_user_can( 'manage_options' ) || ! class_exists( 'Estandar_Integrity_Registry' ) ) {
			return;
		}

		$variant = ( 'summary' === ( $args['variant'] ?? 'full' ) ) ? 'summary' : 'full';
		$limit   = absint( $args['limit'] ?? 0 );

		$findings = estandar_core_integrity_findings();
		$cards    = $findings['cards'];
		$fixable  = $findings['fixable'];
		$manual   = $findings['manual'];
		$total    = count( $cards );

		if ( 'summary' === $variant && 0 === $total ) {
			return; // config page: nothing to summarize, stay silent
		}

		$nonce   = wp_create_nonce( Estandar_Integrity_Controller::NONCE );
		$eco_url = admin_url( 'admin.php?page=estandar_core_diagnostics&tab=estado_ecosistema' );

		echo '<div class="es-diag-section es-integrity-console" style="margin-bottom:12px;" data-nonce="' . esc_attr( $nonce ) . '" data-variant="' . esc_attr( $variant ) . '">';
		echo '<div class="es-diag-section-header">Auditor de integridad' . ( 'summary' === $variant ? ' <span style="font-weight:400;color:#787c82;font-size:11px;">resumen</span>' : '' ) . '</div>';
		echo '<div class="es-diag-section-body" style="padding:12px 16px;">';

		// ── Summary bar ───────────────────────────────────────────────────
		echo '<div class="es-integrity-summary" style="display:flex;align-items:center;gap:14px;flex-wrap:wrap;margin-bottom:12px;font-size:13px;">';
		if ( 0 === $total ) {
			echo '<span style="color:#166534;">✓ Sin hallazgos de integridad.</span>';
		} else {
			echo '<strong style="color:#1d2327;"><span data-es-count="total">' . esc_html( (string) $total ) . '</span> problema' . ( 1 === $total ? '' : 's' ) . ' detectado' . ( 1 === $total ? '' : 's' ) . '</strong>';
			if ( $fixable > 0 ) {
				echo '<span style="color:#166534;">✓ Corregibles automáticamente (<span data-es-count="fixable">' . esc_html( (string) $fixable ) . '</span>)</span>';
			}
			if ( $manual > 0 ) {
				echo '<span style="color:#646970;">○ Requieren intervención manual (<span data-es-count="manual">' . esc_html( (string) $manual ) . '</span>)</span>';
			}
			// RFC: all findings fixable → "Corregir todo"; partial → fix the
			// supported ones and leave manual findings visible.
			// Gate: with autofix disabled $fixable is already 0 (can_auto_fix()
			// refuses); the explicit check is defense in depth.
			if ( 'full' === $variant && $fixable > 0 && estandar_integrity_autofix_enabled() ) {
				$fix_all_label = ( $fixable === $total ) ? 'Corregir todo' : 'Corregir automáticos (' . $fixable . ')';
				echo '<button type="button" class="button button-primary es-integrity-fix-all" style="margin-left:auto;">' . esc_html( $fix_all_label ) . '</button>';
			}
			if ( 'summary' === $variant ) {
				echo '<a href="' . esc_url( $eco_url ) . '" style="margin-left:auto;font-size:12px;">Auditor de integridad completo →</a>';
			}
		}
		echo '</div>';

		// ── Findings ─────────────────────────────────────────────────────
		$shown = ( 'summary' === $variant && $limit > 0 ) ? array_slice( $cards, 0, $limit ) : $cards;

		echo '<div class="es-integrity-cards" style="display:flex;flex-direction:column;gap:' . ( 'summary' === $variant ? '6px' : '10px' ) . ';">';
		foreach ( $shown as $entry ) {
			/** @var Estandar_Integrity_Rule $rule */
			$rule  = $entry['rule'];
			$check = $entry['check'];
			$caps  = $rule->capabilities();

			if ( 'summary' === $variant ) {
				// Compact row: problem · severity · module · fix availability.
				echo '<div class="es-integrity-card" data-rule-id="' . esc_attr( $rule->id() ) . '" data-fixable="' . ( $rule->can_auto_fix() ? '1' : '0' ) . '" style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;padding:7px 10px;background:#fff;border:1px solid #f0f0f1;border-radius:5px;font-size:12px;">';
				echo estandar_core_integrity_severity_chip( $rule->severity() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				echo '<span style="color:#1d2327;font-weight:600;">' . esc_html( $rule->label() ) . '</span>';
				echo '<span style="color:#787c82;">' . esc_html( $rule->plugin_label() ) . '</span>';
				echo $rule->can_auto_fix()
					? '<span style="color:#166534;font-size:11px;">✓ corrección automática</span>'
					: '<span style="color:#787c82;font-size:11px;">○ manual</span>';
				echo '<span style="margin-left:auto;display:flex;gap:6px;align-items:center;">';
				if ( in_array( 'auto_fix', $caps, true ) ) {
					echo '<button type="button" class="button button-small button-primary es-integrity-fix">Corregir automáticamente</button>';
				}
				echo '<a class="button button-small" href="' . esc_url( $eco_url ) . '">Ver detalles</a>';
				echo '</span>';
				echo '<div class="es-integrity-result" style="display:none;flex-basis:100%;"></div>';
				echo '</div>';
				continue;
			}

			// Full card — RFC layout: problem / severity / module / fix availability.
			echo '<div class="es-integrity-card" data-rule-id="' . esc_attr( $rule->id() ) . '" data-fixable="' . ( $rule->can_auto_fix() ? '1' : '0' ) . '" style="border:1px solid #e5e7eb;border-radius:6px;padding:12px 14px;background:#fff;">';

			echo '<div style="display:flex;align-items:baseline;gap:8px;flex-wrap:wrap;">';
			echo estandar_core_integrity_severity_chip( $rule->severity() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<strong style="font-size:13px;color:#1d2327;">' . esc_html( $rule->label() ) . '</strong>';
			echo '<span style="font-size:11px;color:#787c82;">' . esc_html( $rule->rule_code() ) . '</span>';
			echo '</div>';

			echo '<div style="display:grid;grid-template-columns:110px 1fr;gap:2px 10px;margin-top:8px;font-size:12px;">';
			echo '<span style="color:#787c82;font-weight:600;">Módulo</span><span style="color:#1d2327;">' . esc_html( $rule->plugin_label() ) . '</span>';
			echo '<span style="color:#787c82;font-weight:600;">Problema</span><span style="color:#50575e;" data-es-status>' . esc_html( $check->status_text() ) . '</span>';
			echo '<span style="color:#787c82;font-weight:600;">Corrección</span><span style="color:' . ( $rule->can_auto_fix() ? '#166534' : '#787c82' ) . ';">' . ( $rule->can_auto_fix() ? '✓ Automática disponible' : '○ Manual' ) . '</span>';
			echo '</div>';

			// Details area — long/technical explanation lives ONLY here.
			echo '<div class="es-integrity-details" style="display:none;margin-top:8px;padding:8px 10px;background:#f9fafb;border-radius:4px;font-size:12px;color:#50575e;">';
			echo '<p style="margin:0 0 6px;"><strong>Riesgo:</strong> ' . esc_html( $rule->description() ) . '</p>';
			echo '<p style="margin:0 0 6px;">' . esc_html( $rule->technical() ) . '</p>';
			$evidence = $check->evidence();
			if ( ! empty( $evidence['handles'] ) ) {
				echo '<code style="font-size:11px;">' . esc_html( implode( ' · ', array_map( 'strval', (array) $evidence['handles'] ) ) ) . '</code>';
			}
			echo '<div class="es-integrity-plan" style="margin-top:6px;"></div>';
			echo '</div>';

			// Result strip (filled by JS after fix / re-check).
			echo '<div class="es-integrity-result" style="display:none;margin-top:8px;padding:8px 10px;border-radius:4px;font-size:12px;"></div>';

			// Actions — rendered from capabilities, never from rule type.
			echo '<div style="display:flex;gap:8px;margin-top:10px;flex-wrap:wrap;">';
			if ( in_array( 'auto_fix', $caps, true ) ) {
				echo '<button type="button" class="button button-primary es-integrity-fix">Corregir automáticamente</button>';
			}
			if ( in_array( 'details', $caps, true ) ) {
				echo '<button type="button" class="button es-integrity-toggle">Ver detalles</button>';
			}
			if ( in_array( 'dry_run', $caps, true ) ) {
				echo '<button type="button" class="button es-integrity-dry-run">Vista previa del cambio</button>';
			}
			if ( in_array( 're_run', $caps, true ) ) {
				echo '<button type="button" class="button es-integrity-recheck">Re-verificar</button>';
			}
			if ( '' !== $rule->details_url() ) {
				echo '<a class="button" href="' . esc_url( $rule->details_url() ) . '">Resolver</a>';
			}
			echo '</div>';

			echo '</div>';
		}

		if ( 'summary' === $variant && $limit > 0 && $total > $limit ) {
			echo '<a href="' . esc_url( $eco_url ) . '" style="font-size:12px;">Ver los ' . esc_html( (string) $total ) . ' hallazgos →</a>';
		}
		echo '</div>';

		if ( 'full' === $variant ) {
			echo '<div class="es-integrity-report" style="display:none;margin-top:12px;padding:10px 12px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:6px;font-size:12px;"></div>';
		}

		echo '</div></div>';

		estandar_core_render_integrity_cards_js();
	}
}

if ( ! function_exists( 'estandar_core_render_integrity_cards_js' ) ) {
	/** Shared behavior for every console instance on the page. Printed once. */
	function estandar_core_render_integrity_cards_js(): void {
		static $printed = false;
		if ( $printed ) {
			return;
		}
		$printed = true;
		?>
<script>
(function(){
	var consoles = [].slice.call(document.querySelectorAll(".es-integrity-console"));
	if (!consoles.length) { return; }

	function esc(s) {
		var d = document.createElement("div");
		d.textContent = String(s == null ? "" : s);
		return d.innerHTML;
	}

	consoles.forEach(function(console_){
		var nonce = console_.getAttribute("data-nonce");

		function post(action, ruleId) {
			var body = new FormData();
			body.append("action", action);
			body.append("nonce", nonce);
			if (ruleId) { body.append("rule_id", ruleId); }
			return fetch(ajaxurl, { method: "POST", credentials: "same-origin", body: body })
				.then(function(r){ return r.json(); });
		}

		function setResult(card, kind, html) {
			var box = card.querySelector(".es-integrity-result");
			if (!box) { return; }
			var styles = {
				ok:   "background:#f0fdf4;border:1px solid #bbf7d0;color:#166534;",
				warn: "background:#fff8e1;border:1px solid #fde68a;color:#92400e;",
				err:  "background:#fdecec;border:1px solid #fecaca;color:#991b1b;"
			};
			box.style.cssText = "display:block;margin-top:8px;padding:8px 10px;border-radius:4px;font-size:12px;flex-basis:100%;" + (styles[kind] || styles.warn);
			box.innerHTML = html;
		}

		function updateCounters() {
			var cards = console_.querySelectorAll(".es-integrity-card");
			var total = cards.length, fixable = 0;
			cards.forEach(function(c){ if (c.getAttribute("data-fixable") === "1") { fixable++; } });
			var el;
			if ((el = console_.querySelector("[data-es-count=total]")))   { el.textContent = total; }
			if ((el = console_.querySelector("[data-es-count=fixable]"))) { el.textContent = fixable; }
			if ((el = console_.querySelector("[data-es-count=manual]")))  { el.textContent = total - fixable; }
			if (total === 0) {
				var summary = console_.querySelector(".es-integrity-summary");
				if (summary) { summary.innerHTML = '<span style="color:#166534;">✓ Sin hallazgos de integridad. El aviso del menú se actualizará en la próxima carga.</span>'; }
			}
		}

		function applyReport(card, data) {
			var fix = data.fix || {}, recheck = data.recheck || {};
			var recheckLabel = data.resolved ? "PASS" : "FAIL";
			if (fix.status === "fixed" && data.resolved) {
				setResult(card, "ok", "✓ " + esc(fix.message) + "<br><strong>Integrity re-check: PASS</strong>");
				card.style.transition = "opacity .6s";
				setTimeout(function(){ card.style.opacity = "0.35"; }, 900);
				setTimeout(function(){ card.remove(); updateCounters(); }, 1800);
			} else if (fix.status === "already_compliant") {
				setResult(card, "ok", "○ " + esc(fix.message) + "<br><strong>Integrity re-check: " + recheckLabel + "</strong>");
				if (data.resolved) {
					setTimeout(function(){ card.remove(); updateCounters(); }, 1800);
				}
			} else if (fix.status === "fixed" && !data.resolved) {
				setResult(card, "warn", "✓ " + esc(fix.message) + "<br><strong>Integrity re-check: FAIL</strong> — " + esc(recheck.status_text || ""));
				var st = card.querySelector("[data-es-status]");
				if (st && recheck.status_text) { st.textContent = recheck.status_text; }
			} else {
				setResult(card, "err", "✗ " + esc(fix.message || "La corrección falló.") + "<br><strong>Integrity re-check: " + recheckLabel + "</strong>");
			}
		}

		function busy(btn, on) {
			if (!btn) { return; }
			btn.disabled = on;
			if (on) { btn.setAttribute("data-label", btn.textContent); btn.textContent = "Ejecutando…"; }
			else { btn.textContent = btn.getAttribute("data-label") || btn.textContent; }
		}

		console_.addEventListener("click", function(e){
			var btn = e.target.closest("button");
			if (!btn) { return; }
			var card = btn.closest(".es-integrity-card");

			if (btn.classList.contains("es-integrity-toggle") && card) {
				var det = card.querySelector(".es-integrity-details");
				if (det) {
					var open = det.style.display !== "none";
					det.style.display = open ? "none" : "block";
					btn.textContent = open ? "Ver detalles" : "Ocultar detalles";
				}
				return;
			}

			if (btn.classList.contains("es-integrity-fix") && card) {
				busy(btn, true);
				post("estandar_integrity_fix", card.getAttribute("data-rule-id"))
					.then(function(resp){
						busy(btn, false);
						if (!resp || !resp.success) {
							setResult(card, "err", "✗ " + esc((resp && resp.data && resp.data.message) || "Error de ejecución."));
							return;
						}
						applyReport(card, resp.data);
					})
					.catch(function(){ busy(btn, false); setResult(card, "err", "✗ Error de red."); });
				return;
			}

			if (btn.classList.contains("es-integrity-recheck") && card) {
				busy(btn, true);
				post("estandar_integrity_recheck", card.getAttribute("data-rule-id"))
					.then(function(resp){
						busy(btn, false);
						if (!resp || !resp.success) { setResult(card, "err", "✗ No se pudo re-verificar."); return; }
						var recheck = resp.data.recheck || {};
						var st = card.querySelector("[data-es-status]");
						if (st && recheck.status_text) { st.textContent = recheck.status_text; }
						if (resp.data.resolved) {
							setResult(card, "ok", "○ Ya conforme.<br><strong>Integrity re-check: PASS</strong>");
							setTimeout(function(){ card.remove(); updateCounters(); }, 1500);
						} else {
							setResult(card, "warn", "Sigue pendiente: " + esc(recheck.status_text || ""));
						}
					})
					.catch(function(){ busy(btn, false); });
				return;
			}

			if (btn.classList.contains("es-integrity-dry-run") && card) {
				busy(btn, true);
				post("estandar_integrity_dry_run", card.getAttribute("data-rule-id"))
					.then(function(resp){
						busy(btn, false);
						if (!resp || !resp.success) { setResult(card, "err", "✗ No se pudo calcular la vista previa."); return; }
						var det = card.querySelector(".es-integrity-details");
						var target = card.querySelector(".es-integrity-plan");
						if (det) { det.style.display = "block"; }
						if (!target) { return; }
						var plan = resp.data.plan || {};
						var lines = [];
						Object.keys(plan.files || {}).forEach(function(file){
							var handles = plan.files[file];
							Object.keys(handles).forEach(function(h){
								lines.push(esc(file.split("/").slice(-2).join("/")) + " — " + esc(h) + " × " + esc(handles[h]));
							});
						});
						Object.keys(plan.skipped || {}).forEach(function(h){
							lines.push("omitido: " + esc(h) + " (" + esc(plan.skipped[h]) + ")");
						});
						target.innerHTML = lines.length
							? "<strong>Plan (" + esc(plan.total || 0) + " reemplazos):</strong><br>" + lines.join("<br>")
							: "Sin cambios pendientes.";
					})
					.catch(function(){ busy(btn, false); });
				return;
			}

			if (btn.classList.contains("es-integrity-fix-all")) {
				busy(btn, true);
				post("estandar_integrity_fix_all")
					.then(function(resp){
						busy(btn, false);
						if (!resp || !resp.success) {
							alert((resp && resp.data && resp.data.message) || "Error de ejecución.");
							return;
						}
						var reports = resp.data.reports || [];
						var reportBox = console_.querySelector(".es-integrity-report");
						var lines = [];
						reports.forEach(function(rep){
							var card = console_.querySelector('.es-integrity-card[data-rule-id="' + rep.rule_id + '"]');
							if (card) { applyReport(card, rep); }
							var icon = (rep.fix && rep.fix.status === "fixed") ? "✓" : ((rep.fix && rep.fix.status === "already_compliant") ? "○" : "✗");
							lines.push(
								"<div style='padding:4px 0;border-bottom:1px solid #f0f0f1;'>" +
								"<strong>" + esc(rep.plugin) + "</strong> — " + esc(rep.label) + "<br>" +
								icon + " " + esc(rep.fix ? rep.fix.message : "") + "<br>" +
								"Integrity re-check: <strong>" + (rep.resolved ? "PASS" : "FAIL") + "</strong>" +
								"</div>"
							);
						});
						if (reportBox) {
							reportBox.style.display = "block";
							reportBox.innerHTML = "<strong style='display:block;margin-bottom:6px;'>Reporte de ejecución</strong>" + (lines.join("") || "Sin reglas corregibles.");
						}
					})
					.catch(function(){ busy(btn, false); alert("Error de red."); });
			}
		});
	});
})();
</script>
		<?php
	}
}
