# Attribution Model V1

## Ownership

- **Lead** is the canonical person. Its `first_*` fields are immutable first-touch facts. `latest_touch` is derived from the most recent Submission; it is not duplicated on the Lead.
- **Submission** is one concrete ingress. Its attribution columns (`utm_*`, click identifiers, `landing_url`, `referrer`, `session_id`, touch timestamps, source/form and `submitted_at`) are the `current_touch` snapshot for that ingress.
- **Attribution** is the capture context attached to a Submission. `landing_url` means the page of that Submission, never a generic Lead-level landing.
- **Journey/Event** records observable actions and lifecycle facts. It does not own or replace Submission attribution.

## Consumption

- Current-ingress emails, operation panels, workspaces and exports must read the addressed Submission.
- Lead summaries use immutable `first_*` fields for `first_touch` and the latest Submission for `latest_touch`.
- Missing historical Submission data degrades to an empty/unknown display. It must not be fabricated or backfilled from another ingress.

## Commercial Channel Resolution

`Estandar_Leads_Attribution::resolve_channel()` is the single resolver for Analytics, Commercial Intelligence, OCI diagnostics, exports and notifications. Google precedence is: `gclid`/`gbraid`/`wbraid`; Google traffic with paid `utm_medium` (`cpc`, `ppc`, `paid`, `paid_search`, `google_ads`); `utm_medium=organic`; Google Search referrer; existing fallback. `utm_source=google` alone never resolves to Google Ads.

Meta Ads requires Meta acquisition evidence (`fbclid`, `meta_fbc`, or an explicit Meta/Facebook/Instagram source). `meta_fbp` (`_fbp`) is a browser identifier, not acquisition evidence, and never changes an organic/direct/referral/form channel into Meta Ads.

Without source, medium or click identifiers, a Google Search referrer remains `Google Organic`; an explicit direct source or valid session resolves to `Direct`; an Estandar Forms capture resolves to `Formulario Web`; otherwise the channel is `Unknown`. Missing evidence never fabricates `Organic`.

## Compatibility

Existing Lead `first_*` data remains unchanged. Every new Submission appends its own attribution snapshot, so repeated ingress never overwrites prior attribution.

## SEO Intelligence consumer contract — Fase 3

Para `google_organic`, `Estandar_Leads_SEO_Intelligence` resuelve la landing con `LANDING_PRECEDENCE_V1` (submission explícita → first landing → sesión → referrer → home) y consume únicamente `Estandar_SEO_Intelligence::get_landing_organic_context()`. Leads no consulta tablas SEO ni llama a Google.

La salida es contexto agregado de landing: período, clicks, impressions, CTR, posición media, queries principales y frescura. Las queries llevan siempre `scope=landing_aggregate` y `exact_query_known=false`: no representan ni asignan la búsqueda exacta de una persona. Estados ausente, deshabilitado, sin landing, sin datos, stale o error degradan silenciosamente; nunca cambian clasificación, asignación, estado ni acciones comerciales.

## Google Ads ValueTrack Extension

Google campaign context is captured only from explicit query-string values. Supported canonical keys are `gad_campaignid`/`campaign_id`, `adgroup_id`, `asset_group_id`, `creative_id`, `keyword`, `match_type`, `network`, `device`, `placement`, and `campaign_type`. The incoming aliases `campaignid`, `adgroupid`, `assetgroupid`, `creative`, and `matchtype` are normalized to those canonical keys; Google resolves the corresponding `{campaignid}`, `{adgroupid}`, `{assetgroupid}`, `{creative}`, `{keyword}`, `{matchtype}`, `{network}`, `{device}`, and `{placement}` placeholders before WordPress receives the request.

- Browser first-touch and last-touch state: `estandar_attribution_first_touch` / `estandar_attribution_last_touch` in local storage.
- Session first-touch state: the matching fields in `ea_sessions` (`campaign_id`, `adgroup_id`, `asset_group_id`, `creative_id`, `keyword`, `match_type`, `network`, `google_device`, `placement`, `campaign_type`). Non-empty session values are write-once.
- Submission state: `es_lead_submissions.context_json.google_ads_attribution`, with per-field provenance in `google_ads_attribution_provenance`. Each Submission owns an independent snapshot.
- Lead state: no new `first_*` columns. Existing immutable Lead first-touch fields remain unchanged; detailed Google context is not duplicated from Submission history.
- Commercial AI Context Export: `Origin` exposes Campaign ID, Ad Group ID, Asset Group ID, Creative ID, Keyword, Match Type, Network, Device, Placement, Campaign Type, and provenance. Missing values render as `No disponible`. Explicit captured values precede explicit session values; IDs are never inferred from `utm_campaign` or click identifiers.

`gclid`, `gbraid`, and `wbraid` remain click identifiers only. The application does not decode or infer campaign, ad group, asset group, creative, keyword, network, device, placement, or campaign type from them. `campaign_type` is accepted only when explicitly declared; it is never inferred from a campaign name.

Google Ads must supply ValueTrack values in the final URL suffix or tracking template. Recommended Final URL suffix:

`utm_source=google&utm_medium=cpc&utm_campaign={campaignid}&gad_campaignid={campaignid}&adgroup_id={adgroupid}&asset_group_id={assetgroupid}&creative_id={creative}&keyword={keyword}&match_type={matchtype}&network={network}&device={device}&placement={placement}`
