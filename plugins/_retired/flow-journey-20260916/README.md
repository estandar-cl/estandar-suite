
---

## Añadido 2026-09-16 (segunda limpieza): `LeadAdapter` e `InterestAdapter`

Se retiraron **también** del plugin, tras medir su uso real:

- **`LeadAdapter.php`** — **0 call sites** en todo el repo. Su valor era un mapeo más completo de estados
  legacy (19+): `CLIENT → ACTIVE_CLIENT`, `DELIVERED → ACTIVE_CLIENT`, `SPAM/TEST → INVALID`. Esa tabla de
  equivalencias queda aquí documentada por si se retoma; hoy `journey_state` se mantiene con
  `StateMachine::legacy_to_journey()` (vigente) y la adopción histórica usa `Adoption::project_lead()`.
- **`InterestAdapter.php`** — **6 call sites, todos en su propio test** (`run-flow-interest.php`, que se
  conserva aquí). Traducía el pipeline/captures al Interest Evolution Engine de Core (IEE-7), pero **ningún
  código de producción lo invocaba**.

Ambos estaban `require`ados en el bootstrap (peso muerto + superficie de fallo). Se quitaron del bootstrap,
de `GATE_TESTS` (su test) y del README. `Journey/` queda con **`StateMachine.php`** (grafo, invariantes y
mapeos vigentes).
