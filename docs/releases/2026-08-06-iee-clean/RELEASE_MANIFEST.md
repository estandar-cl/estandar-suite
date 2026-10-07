# RELEASE MANIFEST — Interest Evolution Engine (IEE)

**Release:** `2026-08-06-iee-clean`
**Tipo:** Release aislado (únicamente Interest Evolution Engine)
**Versión origen (desplegada):** core 1.0.10 · leads 13.0.163 · flow 1.0.10
  (release unified 2026-08-05)
**Versión destino (este paquete):** core 1.0.13 (IEE-clean) · leads 13.0.167 (IEE-clean) · flow 1.0.13 (IEE-clean)

> Este paquete contiene EXCLUSIVAMENTE el Interest Evolution Engine.
> NO incluye CJE, Calendar, Ads Intelligence, Journey Templates, código
> experimental ni funcionalidades no liberadas.

---

## 1. Propósito

Liberar únicamente el motor de evolución de interés comercial (ADR-0044,
IEE-1..IEE-7) sobre la base actualmente desplegada en producción, SIN arrastrar
el trabajo CJE que todavía no está aprobado para producción.

## 2. Archivos incluidos (17)

| # | Ruta | Tipo | SHA-256 |
|---|------|------|---------|
| 1 | estandar-core/includes/Interest/InterestSignals.php | NUEVO | 2a90150c… |
| 2 | estandar-core/includes/Interest/InterestChannel.php | NUEVO | c6e116af… |
| 3 | estandar-core/includes/Interest/InterestState.php | NUEVO | 1197928d… |
| 4 | estandar-core/includes/Interest/InterestPriority.php | NUEVO | d8f690d3… |
| 5 | estandar-core/includes/Interest/NextBestAction.php | NUEVO | b34de05c… |
| 6 | estandar-core/includes/Interest/InterestEngine.php | NUEVO | 49c52290… |
| 7 | estandar-core/includes/Interest/InterestEventLog.php | NUEVO | a998fac8… |
| 8 | estandar-core/includes/Interest/InterestContext.php | NUEVO | 7d314147… |
| 9 | estandar-core/includes/Interest/EngagementEngine.php | NUEVO | d8c67324… |
| 10 | estandar-leads/includes/class-estandar-leads-interest-adapter.php | NUEVO | fb2803c6… |
| 11 | estandar-leads/includes/class-estandar-leads-interest-listener.php | NUEVO | 20a70adf… |
| 12 | estandar-flow/includes/Journey/InterestAdapter.php | NUEVO | 800fa5cd… |
| 13 | estandar-core/estandar-core.php | MODIFICADO (loader mínimo) | 2929be5c… |
| 14 | estandar-leads/estandar-leads.php | MODIFICADO (loader mínimo) | 0d30fe5c… |
| 15 | estandar-flow/estandar-flow.php | MODIFICADO (loader mínimo) | ce5b9cbc… |
| 16 | estandar-leads/includes/class-estandar-leads-notifier.php | MODIFICADO | b52b0761… |
| 17 | estandar-leads/templates/email/lead-notification.php | MODIFICADO | 0fb867db… |

NOTA: los hashes completos se listan en §8.

## 3. Archivos EXCLUIDOS (a propósito)

- Todo `estandar-core/includes/Calendar.php`, `AdsIntelligence.php`,
  `JourneyTemplates.php`, `rest/` → CJE (no aprobado).
- Todo `estandar-flow/includes/Journey/{StateMachine,LeadAdapter,ReEngagement,
  Recovery,Outcomes}.php` y `register_activation_hook` de Outcomes → CJE.
- Los 3 loaders del workspace (con líneas CJE) → reemplazados por loaders
  mínimos basados en el release base.
- Tests/docs/utilitarios de desarrollo.
- Cualquier archivo con datos de entorno, secretos o credenciales.

## 4. Loaders modificados (versiones mínimas)

Cada loader es la versión del RELEASE BASE + únicamente las líneas IEE:

- **estandar-core.php**: release 1.0.10 + 9 `require_once` de `includes/Interest/*`.
  NO añade Calendar/AdsIntelligence/JourneyTemplates ni sus hooks/seeds.
- **estandar-leads.php**: release 13.0.163 + 2 `require_once` (adapter, listener)
  + `Estandar_Leads_Interest_Listener::init()`.
- **estandar-flow.php**: release 1.0.10 + 1 `require_once` de
  `includes/Journey/InterestAdapter.php`. NO añade Journey/StateMachine ni
  activate_schema de Outcomes.

## 5. Dependencias verificadas

- El IEE depende ÚNICAMENTE de sí mismo (`Estandar\Core\Interest`) y de clases
  legacy presentes en el release base (Estandar_Leads_Attribution,
  Estandar_Leads_Journey_Builder, etc.). CERO dependencia del CJE.
- Loader de core: 48 requires resuelven en el entorno real.
- Loader de leads: 73 requires resuelven en el entorno real.
- Loader de flow: 8 requires resuelven en el entorno real.
- Notifier y template del paquete son IDÉNTICOS a los del workspace testeado.

## 6. Instrucciones de instalación manual (SiteGround)

### Paso 1 — Respaldar (dentro de SiteGround)
Descargar una copia de los archivos a reemplazar:
```
wp-content/plugins/estandar-leads/templates/email/lead-notification.php
wp-content/plugins/estandar-leads/includes/class-estandar-leads-notifier.php
wp-content/plugins/estandar-core/estandar-core.php
wp-content/plugins/estandar-leads/estandar-leads.php
wp-content/plugins/estandar-flow/estandar-flow.php
```

### Paso 2 — Subir archivos nuevos
```
wp-content/plugins/estandar-core/includes/Interest/           (9 archivos)
wp-content/plugins/estandar-leads/includes/class-estandar-leads-interest-adapter.php
wp-content/plugins/estandar-leads/includes/class-estandar-leads-interest-listener.php
wp-content/plugins/estandar-flow/includes/Journey/InterestAdapter.php   (crear dir Journey si no existe)
```

### Paso 3 — Reemplazar archivos modificados
```
wp-content/plugins/estandar-core/estandar-core.php
wp-content/plugins/estandar-leads/estandar-leads.php
wp-content/plugins/estandar-flow/estandar-flow.php
wp-content/plugins/estandar-leads/includes/class-estandar-leads-notifier.php
wp-content/plugins/estandar-leads/templates/email/lead-notification.php
```

### Paso 4 — NO subir
NO reemplazar ningún otro archivo. En particular:
- NO subir los loaders del workspace (contienen CJE).
- NO subir Calendar.php / AdsIntelligence.php / JourneyTemplates.php.
- NO subir includes/Journey/{StateMachine,LeadAdapter,ReEngagement,Recovery,Outcomes}.php.

### Paso 5 — Limpiar caché
Purgar caché de opcache del sitio (SiteGround > Speed > Caching > Purge) y
verificar que los plugins siguen activos.

## 7. Instrucciones de rollback

1. Restaurar los 5 archivos respaldados en el Paso 1 (versiones 1.0.10/13.0.163).
2. Eliminar los 12 archivos nuevos (Interest/*, adapters, InterestAdapter).
3. Purgar caché de opcache.
4. Verificar que el correo vuelve a mostrar "El prospecto aún no ha sido
   contactado." (comportamiento legacy).
5. No hay cambios de base de datos que revertir (no se crea tabla
   automáticamente).

## 8. Hashes SHA-256 completos

```
2929be5c0c9a169c2a9e62fe597f63fcad97ad4deea6e102b35f79681e854586  estandar-core/estandar-core.php
d8c6732468281324e372140e1941b011c250955534021768e95b1d627e26f37a  estandar-core/includes/Interest/EngagementEngine.php
c6e116af7e1158f1e5923841ae652c2e13656ae20986edb0c3f7ba0bd4e608f1  estandar-core/includes/Interest/InterestChannel.php
7d31414730e66c1470a99f53db4175811f013ad616afa743a9dd21fe10b2313e  estandar-core/includes/Interest/InterestContext.php
49c522905b7047c9b895a45b3b202955238a6a69be709785db34c75e62212cf5  estandar-core/includes/Interest/InterestEngine.php
a998fac83f35104da29709fd75600af768b259250ca53ba71ab4adfcd1a680ad  estandar-core/includes/Interest/InterestEventLog.php
d8f690d37f0f38e75d210f804e835a3d5e08254808ac5bf45ef2eea6feba22e4  estandar-core/includes/Interest/InterestPriority.php
2a90150c58ae89c64535bb3d82c6b5470f881662d77a0df081bf62e2f725d3e3  estandar-core/includes/Interest/InterestSignals.php
1197928d5efcefb4786f3ce7c90fc906d5069ed587a7674ca39cc88c33f483c7  estandar-core/includes/Interest/InterestState.php
b34de05c42b676ed8cf9cef863db3fa15ce8c80140d81e4fd6bc24a60186d354  estandar-core/includes/Interest/NextBestAction.php
ce5b9cbc436844965d00cddee93e6590b029dc2bbe9855afa29fe8747f8fded4  estandar-flow/estandar-flow.php
800fa5cd470dab803cb1e606fc2332cceac3bca8be5222ebfdb90137d3288d2a  estandar-flow/includes/Journey/InterestAdapter.php
0d30fe5c06fbeb95a8110a0d33db813841f526c19fb40fc53ae76cad156b057a  estandar-leads/estandar-leads.php
fb2803c6e961cc8638137266d8c3f07862c11b5ae92b3c1593b78903599dc11a  estandar-leads/includes/class-estandar-leads-interest-adapter.php
20a70adf7dca3a6317ecc2874ffe33ec9c94044147cdc7885b5e3ac640e17183  estandar-leads/includes/class-estandar-leads-interest-listener.php
b52b0761eef3743998ad4f5301c4991884268dac04cb4d11286bc18c23144306  estandar-leads/includes/class-estandar-leads-notifier.php
0fb867db36fea6e12f60844211164b75ca26118eafe33055128a192498d39cbd  estandar-leads/templates/email/lead-notification.php
```

## 9. Reutilización futura

Este paquete es reutilizable para AK Contadores y otras instalaciones:
el motor vive en estandar-core (compartido); los adapters de leads y flow
traducen datos específicos; el EngagementEngine se configura por proyecto
(option/filtro) sin conocimiento de negocio.
