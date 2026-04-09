<?php
/**
 * DC Script Worker Proxy — Admin Interface
 * Partytown third-party script offloading + viewport/pagination prefetching
 * DampCig.dk
 *
 * @package DC_Service_Worker_Proxy
 */

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	die(); }

// ── Locale strings ────────────────────────────────────────────────────────────
// English is the default; Danish is used when WP locale starts with "da_".
/**
 * Return a localised admin UI string for the given key.
 *
 * @since 1.0.0
 * @param string $key The string key to look up.
 * @return string The localised string, or the key itself if not found.
 */
function dc_swp_str( $key ) {
	static $s = null;
	if ( null === $s ) {
		$da = strncmp( get_locale(), 'da_', 3 ) === 0;
		$s  = $da ? array(
			'page_title'                  => 'SW Prefetch Indstillinger',
			'saved'                       => 'Indstillinger gemt!',
			'info_title'                  => 'Partytown Integration',
			'info_body'                   => 'I modsætning til async/defer — som kun forsinker indlæsning, men stadig kører scripts på main-tråden — afvikler Partytown tredjeparts-scripts i en Web Worker. Browser main-tråden berøres aldrig: ingen layout-jank, ingen TBT-straf, ingen konkurrence med brugerinteraktioner. Officielt testede og kompatible tjenester: Google Tag Manager, Facebook Pixel, HubSpot, Intercom, Klaviyo, TikTok Pixel og Mixpanel. Aktivér Samtykkeporten for at blokere scripts indtil besøgende har givet samtykke via WP Consent API.',
			'sw_label'                    => 'Aktiver Partytown',
			'sw_desc'                     => 'Aktiver Partytown service worker til offloading af tredjeparts-scripts og viewport-prefetch. Deaktiveret = diagnostiktilstand: scripts afvikles direkte på main-tråden med defer (ingen Web Worker, ingen samtykke-gating).',
			'preload_label'               => 'Viewport Preloading',
			'preload_desc'                => '<strong>Anbefalet!</strong> Preloader automatisk produkter synlige i viewporten via browser prefetch. Benytter W3TC cache for øjeblikkelig indlæsning når brugeren klikker.',
			'strategy_title'              => 'Arkitektur',
			'html_label'                  => 'Tredjeparts-scripts',
			'html_val'                    => 'Afvikles i en Web Worker via Partytown (ikke på main-tråden)',
			'html_desc'                   => 'I modsætning til async/defer (der kun forsinker indlæsning) afvikler Partytown scripts i en Web Worker. Browser main-tråden blokeres aldrig — brugerinteraktion og rendering påvirkes ikke, selv mens analytics fyres.',
			'static_label'                => 'HTML-sider',
			'static_val'                  => 'Håndteres af W3 Total Cache',
			'static_desc'                 => 'Produktsider og kategorier caches af W3TC — Partytown interfererer ikke med HTML-cachen.',
			'benefits_title'              => 'Fordele',
			'benefit_1'                   => 'Analysescripts afvikles i en Web Worker — i modsætning til async kører de aldrig på main-tråden',
			'benefit_2'                   => 'Viewport-prefetch preloader produktlinks automatisk',
			'benefit_3'                   => 'Pagineringslink prefetches 2 s i forvejen',
			'benefit_4'                   => 'Bots og crawlers modtager aldrig Partytown (rent HTML)',
			'benefit_5'                   => 'Automatiske opdateringer via GitHub Actions workflow',
			'benefit_6'                   => 'WP emoji-scripts fjernet — sparer et DNS-opslag og ~76 KB',
			'benefit_7'                   => 'Tredjeparts-scripts auto-detekteres og offloades til Partytown med ét klik',
			'benefit_8'                   => 'Samtykke-bevidst: valgfri Samtykkeport blokerer scripts (text/plain) indtil samtykke er givet via WP Consent API',
			'partytown_scripts_label'     => 'Partytown Script Liste',
			'partytown_scripts_desc'      => 'Angiv én URL eller søgestreng per linje. Matcher mod script src. Kun officielt testede tjenester anbefales: <strong>HubSpot</strong>, <strong>Intercom</strong>, <strong>Klaviyo</strong>, <strong>TikTok Pixel</strong>, <strong>Mixpanel</strong>, <strong>FullStory</strong> (<code>fullstory.com</code>). <a href="https://partytown.qwik.dev/common-services/" target="_blank" rel="noopener">Fuld liste ↗</a>',
			'partytown_autodetect_btn'    => '🔍 Auto-Detekter Tredjeparts-Scripts',
			'partytown_autodetect_none'   => 'Ingen eksterne scripts fundet på forsiden.',
			'partytown_autodetect_add'    => 'Tilføj Valgte til Liste',
			'partytown_autodetect_warn'   => 'Ukendt kompatibilitet — ikke på Partytowns verificerede liste. Test grundigt, før du tilføjer til listen.',
			'partytown_autodetect_known'  => '✔ Verificeret kompatibel tjeneste',
			'inline_scripts_label'        => 'Indlejrede Script Blokke',
			'inline_scripts_desc'         => 'Indsæt komplette tredjeparts-script-blokke her — inkl. &lt;script&gt;-tags og &lt;noscript&gt;-fallbacks (Meta Pixel, TikTok Pixel osv.). Plugin\'et konverterer dem automatisk til <code>type="text/partytown"</code> så de køres i en Web Worker og respekterer marketingsamtykke. <a href="https://partytown.qwik.dev/common-services/" target="_blank" rel="noopener">Kompatible tjenester ↗</a>',
			'inline_scripts_add_title'    => 'Tilføj Script Blok',
			'inline_scripts_lbl_ph'       => 'Navn, f.eks. Meta Pixel',
			'inline_scripts_add_btn'      => '+ Tilføj Blok',
			'inline_scripts_empty'        => 'Ingen script-blokke tilføjet endnu.',
			'inline_scripts_del_confirm'  => 'Slet denne script-blok?',
			'inline_scripts_imported'     => 'Importerede Scripts',
			'emoji_label'                 => 'Fjern WP Emoji',
			'emoji_desc'                  => 'Fjerner WordPress emoji-detection script og tilhørende CSS (s.w.org fetch). Anbefalet — moderne browsere har native emoji.',
			'coi_label'                   => 'SharedArrayBuffer (Atomics Bridge)',
			'coi_desc'                    => 'Sender <code>Cross-Origin-Opener-Policy: same-origin</code> og <code>Cross-Origin-Embedder-Policy: credentialless</code> på offentlige sider. Aktiverer <code>crossOriginIsolated</code> i browseren, så Partytown skifter til den hurtigere Atomics-bro i stedet for sync-XHR. Skip bots, indloggede brugere og kassen. Alle cross-origin iframes får automatisk <code>credentialless</code>-attributten, så de kan indlæses under COEP — uanset ekskluderingslisten. <strong>Test i staging — kan bryde OAuth-popups eller andre cross-origin iframes.</strong>',
			'credit_label'                => 'Footer Kredit',
			'credit_checkbox'             => 'Vis kærlighed og støt udviklingen ved at tilføje et lille link i footeren',
			'credit_desc'                 => 'Indsætter et diskret <a href="https://www.dampcig.dk" target="_blank">Dampcig.dk</a>-link i sidens footer ved at linke copyright-symbolet ©.',
			'save_button'                 => 'Gem Indstillinger',
			'pt_version_label'            => 'Partytown Version',
			'consent_mode_label'          => 'Google Consent Mode v2',
			'consent_mode_desc'           => 'Global samtykkemyndighed for alle GCM v2-kompatible tjenester. Injicerer et 7-parameter <code>gtag("consent","default",{...})</code>-kodestykke i &lt;head&gt; inden nogen scripts indlæses — med per-kategori samtykke (marketing → annoncer, statistik → analyse, præferencer → personalisering). Når aktivt, afvikles scripts for GCM v2-bevidste tjenester (Google Tag Manager, Hotjar, LinkedIn Insight, TikTok Pixel, Microsoft Clarity) altid som <code>text/partytown</code>. <strong>Kræver GTM eller gtag.js samt en GCM v2-kompatibel CMP.</strong>',
			'url_passthrough_label'       => 'URL Passthrough',
			'url_passthrough_desc'        => 'Aktiverer <code>gtag(\"set\",\"url_passthrough\",true)</code>. Bevarar gclid / wbraid-parametre i URL-adresser, så konverteringsattribution fungerer cookiefrit — selv når <code>ad_storage</code> er nægtet. Anbefales til Google Ads-annoncører.',
			'ads_data_redaction_label'    => 'Annonce-dataredigering',
			'ads_data_redaction_desc'     => 'Aktiverer <code>gtag(\"set\",\"ads_data_redaction\",true)</code>. Redigerer klik-id\'er (gclid, wbraid) fra data sendt til Google, når <code>ad_storage</code> er nægtet — øget privatlivsbeskyttelse for besøgende der ikke har givet markedsføringsamtykke.',
			'meta_ldu_label'              => 'Meta Pixel Limited Data Use (LDU)',
			'meta_ldu_desc'               => 'Meta/Facebook Pixel understøtter ikke Google Consent Mode v2 — det bruger sin egen Limited Data Use (LDU) samtykke-API. Injicerer et fbq-stub + <code>fbq("dataProcessingOptions",["LDU"],0,0)</code> i &lt;head&gt; inden Partytown og Facebook Pixel-scripts indlæses. Meta Pixel afvikles altid som <code>text/partytown</code> — Meta anvender LDU-begrænsninger internt (ingen data brugt til annoncemålretning). Din CMP behøver ikke blokere scriptet via <code>text/plain</code>. Kræver at Meta Pixel er tilføjet via Partytown Script Liste eller en Inline Script Blok.',
			'debug_label'                 => 'Partytown Debug-tilstand',
			'debug_desc'                  => 'Indlæser den uminificeret debug-version af Partytown og aktiverer alle log-flag. Log-output sendes via <code>console.debug()</code> — husk at aktivere <strong>Verbose</strong>-niveauet i DevTools-konsollen (standardfilter skjuler det). Worker-logge fra web workeren vises kun i <strong>Atomics Bridge</strong>-tilstand, som kræver at <em>COI-headers</em> er aktiveret ovenfor. <strong>Brug kun i staging eller lokalt udviklingsmiljø — åbner verbose-logging for alle besøgende.</strong>',
			'consent_info_toggle'         => 'Samtykke-arkitektur & GCM v2-tjenester',
			'consent_info_services_title' => 'GCM v2-bevidste tjenester',
			'consent_info_services_desc'  => 'Disse tjenester læser selv GCM v2-samtykketilstanden og begrænser dataindsamling internt — ingen text/plain-blokering er nødvendig, når GCM v2 er aktivt.',
			'consent_info_meta_title'     => 'Meta Pixel — separat LDU-mekanisme',
			'consent_info_meta_desc'      => 'Meta Pixel understøtter ikke GCM v2. Aktiver Meta Pixel LDU nedenfor — Meta anvender LDU-begrænsninger internt.',
			'consent_gate_label'          => 'Samtykkeport (WP Consent API)',
			'consent_gate_desc'           => 'Når aktiveret, blokeres scripts som <code>type="text/plain"</code> indtil besøgende har givet samtykke via WP Consent API. Kræver et CMP-plugin der integrerer med <a href="https://wordpress.org/plugins/wp-consent-api/" target="_blank" rel="noopener">WP Consent API</a>. Når deaktiveret (standard), indlæses alle scripts ubetinget.',
			'consent_gate_notice'         => '⚠️ Samtykkeport er aktiveret, men WP Consent API-pluginnet er ikke installeret. Scripts vil blive blokeret for alle besøgende.',
			'script_list_category_label'  => 'Script Liste standardkategori',
			'script_list_category_desc'   => 'WP Consent API-kategorien der bruges til at gatekeeper Script Liste-poster.',
			'block_category_label'        => 'Samtykke-kategori',
			'gcm_conflict_checking'       => 'Søger efter GCM v2-konflikter…',
			'gcm_conflict_title'          => '⚠ Eksisterende GCM v2-stub fundet',
			'gcm_conflict_body'           => 'Et andet plugin eller tema på dit site outputter allerede et gtag(\'consent\',\'default\',...)-kald. To GCM v2-stubs kan ikke køre samtidig — hvem der fyrer sidst vinder, og det er ikke-deterministisk. Deaktiver Google Consent Mode i det andet plugin, inden du aktiverer det her.',
			'gcm_no_consent_api_title'    => 'WP Consent API er ikke installeret',
			'gcm_no_consent_api_body'     => 'Vores GCM v2-opdateringsscript læser samtykkestatus via WP Consent API-pluginnet. Uden det kan samtykkesignaler ikke sendes pålideligt til Google på tværs af CMP-plugins.',
			'gcm_no_consent_api_link'     => 'Installer WP Consent API ↗',
			'badge_supported'             => '✓ Understøttet | Partytown',
			'badge_unsupported'           => '⚠ Ikke understøttet | Udskudt',
			'force_pt_label'              => 'Tving Partytown aktivt',
			'force_pt_notice'             => 'Kører script med ukendt Partytown-kompatibilitet — test dit site i debug-tilstand for at bekræfte ingen renderingsfejl.',
			'gtm_section_label'           => 'Google Tag Management',
			'gtm_mode_off'                => 'Deaktiveret — ingen tag-styring',
			'gtm_mode_own'                => 'Angiv Tag-ID — jeg har mit eget GTM- eller GA4-ID',
			'gtm_mode_detect'             => 'Auto-Detekter — find aktivt tag i sidens kildekode',
			'gtm_mode_managed'            => 'Opsætningsguide — trin-for-trin GTM-onboarding',
			'gtm_id_placeholder'          => 'GTM-XXXXXXX eller G-XXXXXXXXXX',
			'gtm_id_invalid'              => '⚠ Ugyldigt format. Forventet: GTM-XXXXXXX, G-XXXXXXXXXX eller UA-XXXXXX-X.',
			'gtm_id_valid'                => '✔ Gyldigt tag-ID',
			'gtm_detect_btn'              => 'Scan hjemmeside',
			'gtm_detect_none'             => 'Intet aktivt Google Tag fundet i sidens kildekode.',
			'gtm_detect_auto_switched'    => '✔ Auto-Detekter valgt — tagget er allerede i Partytown-scriptlisten.',
			'gtm_detect_found'            => 'Fundet',
			'gtm_detect_use'              => 'Brug dette ID',
			'gtm_detect_will_use'         => 'bruges ved næste gem',
			'gtm_detect_active'           => 'Auto-detekteret og aktiv',
			'gtm_wizard_step1_title'      => 'Trin 1 — Opret GTM-konto og container',
			'gtm_wizard_step1_body'       => 'Gå til <a href="https://tagmanager.google.com" target="_blank" rel="noopener">tagmanager.google.com ↗</a>, log ind, klik <strong>Opret konto</strong>, angiv kontonavn + land, tilføj en Container (brug websiteadressen som navn), vælg type <strong>Web</strong> og klik <strong>Opret</strong>.',
			'gtm_wizard_step2_title'      => 'Trin 2 — Angiv dit Container-ID',
			'gtm_wizard_step2_body'       => 'Dit <strong>Container-ID</strong> vises øverst til højre i GTM-grænsefladen (format: <code>GTM-XXXXXXX</code>). Kopiér det og indsæt det herunder.',
			'gtm_wizard_step3_title'      => 'Trin 3 — Tilføj tags i GTM',
			'gtm_wizard_step3_body'       => 'Opret tags inde i GTM, f.eks. <strong>Google Analytics 4</strong> (brug "Google Tag"-konfiguration med dit <code>G-XXXXXXXXXX</code> Measurement ID), <strong>LinkedIn Insight Tag</strong>, <strong>TikTok Pixel</strong> osv. Sæt triggeren til <em>Alle sider</em>. GCM v2-samtykketilstand styrer automatisk dataindsamling pr. besøgende.',
			'gtm_wizard_step4_title'      => 'Trin 4 — Publicér og bekræft',
			'gtm_wizard_step4_body'       => 'Klik <strong>Send</strong> → <strong>Publicér</strong> i GTM for at udgive containeren. Pluginnet injicerer GTM-kodestykket i <code>&lt;head&gt;</code> med GCM v2-samtykke præ-konfigureret. Klik <strong>Fuldfør opsætning</strong> nedenfor for at gemme dit Container-ID.',
			'gtm_wizard_next'             => 'Næste →',
			'gtm_wizard_prev'             => '← Tilbage',
			'gtm_wizard_done'             => '✔ Fuldfør opsætning',
			'gtm_wizard_saved'            => '✔ Gemt',
			'gtm_active_badge'            => 'GTM Aktiv',
			'gtm_ga4_badge'               => 'GA4 Aktiv',
			'gtm_desc_own'                => 'Angiv dit GTM Container-ID eller GA4 Measurement ID. Pluginnet injicerer kodestykket i <code>&lt;head&gt;</code> i korrekt rækkefølge — efter GCM v2-samtykkestandarden men før alle andre scripts.',
			'gtm_desc_detect'             => 'Henter sidens HTML-kildekode og scanner for aktive Google Tags (GTM, GA4, UA). Detekterer kun tags der faktisk er synlige i kildekoden — ikke plugin-indstillinger. GCM v2-samtykkestandarden aktiveres automatisk inden det detekterede tag.',
			'gtm_desc_managed'            => 'Følg trin-for-trin-guiden for at oprette din GTM-container og lad dette plugin injicere og administrere kodestykket.',
		) : array(
			'page_title'                  => 'SW Prefetch Settings',
			'saved'                       => 'Settings saved!',
			'info_title'                  => 'Partytown Integration',
			'info_body'                   => 'Unlike async/defer — which only delay loading but still execute scripts on the main thread — Partytown runs third-party scripts entirely in a Web Worker. The browser main thread is never touched: no layout jank, no TBT penalty, no competition with user interactions. Officially tested compatible services: Google Tag Manager, Facebook Pixel, HubSpot, Intercom, Klaviyo, TikTok Pixel, and Mixpanel. Enable the Consent Gate to block scripts until visitor consent is granted via the WP Consent API.',
			'sw_label'                    => 'Enable Partytown',
			'sw_desc'                     => 'Activate Partytown service worker for third-party script offloading and viewport prefetch. When disabled, scripts render directly on the main thread with defer — useful for diagnosing Partytown issues (no Web Worker, no consent gating).',
			'preload_label'               => 'Viewport Preloading',
			'preload_desc'                => '<strong>Recommended!</strong> Automatically prefetches products visible in the viewport via browser prefetch, leveraging W3TC cache for instant loading when the user clicks.',
			'strategy_title'              => 'Architecture',
			'html_label'                  => 'Third-party Scripts',
			'html_val'                    => 'Executed in a Web Worker via Partytown (never on the main thread)',
			'html_desc'                   => 'Unlike async/defer (which only delay loading), Partytown executes scripts in a Web Worker. The browser main thread is never blocked — user interactions and rendering are unaffected even while analytics fire.',
			'static_label'                => 'HTML Pages',
			'static_val'                  => 'Handled by W3 Total Cache',
			'static_desc'                 => 'Product pages and categories are cached by W3TC — Partytown does not interfere with HTML caching.',
			'benefits_title'              => 'Benefits',
			'benefit_1'                   => 'Analytics scripts run in a Web Worker — unlike async, they never execute on the browser main thread',
			'benefit_2'                   => 'Viewport prefetch pre-loads product links automatically',
			'benefit_3'                   => 'Pagination next-page link prefetched 2 s ahead',
			'benefit_4'                   => 'Bots and crawlers never receive Partytown (clean HTML)',
			'benefit_5'                   => 'Automatic updates via GitHub Actions workflow',
			'benefit_6'                   => 'WP emoji scripts removed — saves a DNS lookup and ~76 KB',
			'benefit_7'                   => 'Third-party scripts auto-detected and offloaded to Partytown in one click',
			'benefit_8'                   => 'Consent-aware: optional Consent Gate blocks scripts (text/plain) until consent is granted via the WP Consent API',
			'partytown_scripts_label'     => 'Partytown Script List',
			'partytown_scripts_desc'      => 'Enter one URL or pattern per line. Matched against the script <code>src</code> attribute. Only officially tested services are recommended: <strong>HubSpot</strong>, <strong>Intercom</strong>, <strong>Klaviyo</strong>, <strong>TikTok Pixel</strong>, <strong>Mixpanel</strong>, <strong>FullStory</strong> (<code>fullstory.com</code>). <a href="https://partytown.qwik.dev/common-services/" target="_blank" rel="noopener">Full list ↗</a>',
			'partytown_autodetect_btn'    => '🔍 Auto-Detect Third-Party Scripts',
			'partytown_autodetect_none'   => 'No external scripts found on the homepage.',
			'partytown_autodetect_add'    => 'Add Selected to List',
			'partytown_autodetect_warn'   => 'Compatibility unknown — not on Partytown\'s verified services list. Test carefully before adding.',
			'partytown_autodetect_known'  => '✔ Verified compatible service',
			'inline_scripts_label'        => 'Inline Script Blocks',
			'inline_scripts_desc'         => 'Paste complete third-party script blocks here — including &lt;script&gt; tags and &lt;noscript&gt; fallbacks (Meta Pixel, TikTok Pixel, etc.). The plugin automatically converts them to <code>type="text/partytown"</code> so they run in a Web Worker and respect marketing consent. <a href="https://partytown.qwik.dev/common-services/" target="_blank" rel="noopener">Compatible services ↗</a>',
			'inline_scripts_add_title'    => 'Add Script Block',
			'inline_scripts_lbl_ph'       => 'Label, e.g. Meta Pixel',
			'inline_scripts_add_btn'      => '+ Add Block',
			'inline_scripts_empty'        => 'No script blocks added yet.',
			'inline_scripts_del_confirm'  => 'Delete this script block?',
			'inline_scripts_imported'     => 'Imported Scripts',
			'emoji_label'                 => 'Remove WP Emoji',
			'emoji_desc'                  => 'Removes the WordPress emoji detection script and its CSS (s.w.org fetch). Recommended — modern browsers have native emoji support.',
			'coi_label'                   => 'SharedArrayBuffer (Atomics Bridge)',
			'coi_desc'                    => 'Sends <code>Cross-Origin-Opener-Policy: same-origin</code> and <code>Cross-Origin-Embedder-Policy: credentialless</code> on public pages. Enables <code>crossOriginIsolated</code> in the browser so Partytown switches to the faster Atomics bridge instead of the sync-XHR bridge. Skipped for bots, logged-in users and checkout. All cross-origin iframes are automatically given the <code>credentialless</code> attribute so they can load under COEP — regardless of the exclusion list. <strong>Test in staging first — can break OAuth popups or other cross-origin iframes.</strong>',
			'credit_label'                => 'Footer Credit',
			'credit_checkbox'             => 'Show some love and support development by adding a small link in the footer',
			'credit_desc'                 => 'Inserts a discreet <a href="https://www.dampcig.dk" target="_blank">Dampcig.dk</a> link in the footer by linking the copyright symbol ©.',
			'save_button'                 => 'Save Settings',
			'pt_version_label'            => 'Partytown Version',
			'consent_mode_label'          => 'Google Consent Mode v2',
			'consent_mode_desc'           => 'Global consent authority for all GCM v2-compatible services. Injects a full 7-parameter <code>gtag("consent","default",{...})</code> snippet in &lt;head&gt; before any scripts load — with per-category consent signals (marketing → ads, statistics → analytics, preferences → personalisation). When active, scripts for GCM v2-aware services (Google Tag Manager, Hotjar, LinkedIn Insight, TikTok Pixel, Microsoft Clarity) always run as <code>text/partytown</code>. A revoke listener is automatically injected to fire <code>gtag("consent","update",{...denied})</code> if the visitor withdraws consent. <strong>Requires GTM or a gtag.js-based setup together with a GCM v2-compatible CMP.</strong>',
			'url_passthrough_label'       => 'URL Passthrough',
			'url_passthrough_desc'        => 'Enables <code>gtag("set","url_passthrough",true)</code>. Preserves gclid / wbraid parameters in URLs so conversion attribution works cookieless — even when <code>ad_storage</code> is denied. Recommended for Google Ads advertisers.',
			'ads_data_redaction_label'    => 'Ads Data Redaction',
			'ads_data_redaction_desc'     => 'Enables <code>gtag("set","ads_data_redaction",true)</code>. Redacts click IDs (gclid, wbraid) from data sent to Google when <code>ad_storage</code> is denied — enhanced privacy for visitors who have not granted marketing consent.',
			'meta_ldu_label'              => 'Meta Pixel Limited Data Use (LDU)',
			'meta_ldu_desc'               => 'Meta/Facebook Pixel does not support Google Consent Mode v2 — it uses its own Limited Data Use (LDU) consent API. Injects an fbq stub + <code>fbq("dataProcessingOptions",["LDU"],0,0)</code> in &lt;head&gt; before Partytown and Facebook Pixel scripts load. The Meta Pixel always runs as <code>text/partytown</code> — Meta applies LDU restrictions internally (data not used for ad targeting). Your CMP does not need to block the script via <code>text/plain</code>. Requires Meta Pixel to be added via the Partytown Script List or an Inline Script Block.',
			'debug_label'                 => 'Partytown Debug Mode',
			'debug_desc'                  => 'Loads the unminified debug build of Partytown and enables all log flags. Output is emitted via <code>console.debug()</code> — you must enable the <strong>Verbose</strong> level in the DevTools Console filter (hidden by default). Worker-side logs only appear in <strong>Atomics Bridge</strong> mode, which requires the <em>COI Headers</em> option above to be enabled. <strong>Use only in staging or local development — enables verbose logging for all visitors.</strong>',
			'consent_info_toggle'         => 'Consent Architecture & GCM v2 Services',
			'consent_info_services_title' => 'GCM v2-Aware Services',
			'consent_info_services_desc'  => 'These services natively read the GCM v2 consent state and self-restrict data collection — no text/plain blocking is needed when GCM v2 is active.',
			'consent_info_meta_title'     => 'Meta Pixel — Separate LDU Mechanism',
			'consent_info_meta_desc'      => 'Meta Pixel does not implement GCM v2. Enable Meta Pixel LDU below — Meta applies Limited Data Use restrictions internally.',
			'consent_gate_label'          => 'Consent Gate (WP Consent API)',
			'consent_gate_desc'           => 'When enabled, scripts are blocked as <code>type="text/plain"</code> until the visitor grants consent via WP Consent API. Requires a CMP plugin that integrates with <a href="https://wordpress.org/plugins/wp-consent-api/" target="_blank" rel="noopener">WP Consent API</a>. When disabled (default), all scripts load unconditionally.',
			'consent_gate_notice'         => '⚠️ Consent Gate is enabled but the WP Consent API plugin is not installed. Scripts will be blocked for all visitors.',
			'script_list_category_label'  => 'Script List default category',
			'script_list_category_desc'   => 'WP Consent API category used to gate Script List entries.',
			'block_category_label'        => 'Consent category',
			'gcm_conflict_checking'       => 'Checking for GCM v2 conflicts…',
			'gcm_conflict_title'          => '⚠ Existing GCM v2 stub detected',
			'gcm_conflict_body'           => 'Another plugin or theme on your site already outputs a gtag(\'consent\',\'default\',...) call. Running two GCM v2 stubs simultaneously causes unpredictable consent behaviour — whichever fires last wins, non-deterministically. Disable Google Consent Mode in the other plugin before enabling it here.',
			'gcm_no_consent_api_title'    => 'WP Consent API not installed',
			'gcm_no_consent_api_body'     => 'Our GCM v2 update script reads consent state via the WP Consent API plugin. Without it, consent signals cannot be delivered reliably to Google across different CMP plugins.',
			'gcm_no_consent_api_link'     => 'Install WP Consent API ↗',
			'badge_supported'             => '✓ Supported | Partytown',
			'badge_unsupported'           => '⚠ Unsupported | Deferred',
			'force_pt_label'              => 'Force Enable Partytown',
			'force_pt_notice'             => 'Running script with unknown Partytown compatibility — test your site in debug mode to confirm no render errors.',
			'gtm_section_label'           => 'Google Tag Management',
			'gtm_mode_off'                => 'Disabled — no tag management',
			'gtm_mode_own'                => 'Enter Tag ID — I have my own GTM or GA4 ID',
			'gtm_mode_detect'             => 'Auto-Detect — find active tag in page source code',
			'gtm_mode_managed'            => 'Setup Guide — step-by-step GTM onboarding',
			'gtm_id_placeholder'          => 'GTM-XXXXXXX or G-XXXXXXXXXX',
			'gtm_id_invalid'              => '⚠ Invalid format. Expected: GTM-XXXXXXX, G-XXXXXXXXXX, or UA-XXXXXX-X.',
			'gtm_id_valid'                => '✔ Valid tag ID',
			'gtm_detect_btn'              => 'Scan Website',
			'gtm_detect_none'             => 'No active Google Tag found in page source.',
			'gtm_detect_auto_switched'    => '\u2714 Auto-Detect selected \u2014 tag is already in the Partytown Script List.',
			'gtm_detect_found'            => 'Detected',
			'gtm_detect_use'              => 'Use This ID',
			'gtm_detect_will_use'         => 'will be used on next save',
			'gtm_detect_active'           => 'Auto-detected and active',
			'gtm_wizard_step1_title'      => 'Step 1 — Create GTM Account & Container',
			'gtm_wizard_step1_body'       => 'Visit <a href="https://tagmanager.google.com" target="_blank" rel="noopener">tagmanager.google.com ↗</a>, sign in, click <strong>Create Account</strong>, enter an account name and country, add a Container (use your website URL as the name), select <strong>Web</strong> as the platform, then click <strong>Create</strong>.',
			'gtm_wizard_step2_title'      => 'Step 2 — Enter Your Container ID',
			'gtm_wizard_step2_body'       => 'Your <strong>Container ID</strong> appears in the top-right of the GTM interface (format: <code>GTM-XXXXXXX</code>). Copy it and paste it below.',
			'gtm_wizard_step3_title'      => 'Step 3 — Add Tags in GTM',
			'gtm_wizard_step3_body'       => 'Inside GTM, add tags such as <strong>Google Analytics 4</strong> (use the &ldquo;Google Tag&rdquo; configuration tag with your GA4 Measurement ID <code>G-XXXXXXXXXX</code>), <strong>LinkedIn Insight Tag</strong>, <strong>TikTok Pixel</strong>, etc. Set each tag to fire on trigger <em>All Pages</em>. GCM v2 consent mode automatically controls data collection per visitor consent.',
			'gtm_wizard_step4_title'      => 'Step 4 — Publish & Confirm',
			'gtm_wizard_step4_body'       => 'Click <strong>Submit</strong> → <strong>Publish</strong> in GTM to deploy your container. This plugin injects the GTM snippet in <code>&lt;head&gt;</code> with GCM v2 consent pre-configured. Click <strong>Complete Setup</strong> below to save your Container ID.',
			'gtm_wizard_next'             => 'Next →',
			'gtm_wizard_prev'             => '← Back',
			'gtm_wizard_done'             => '✔ Complete Setup',
			'gtm_wizard_saved'            => '✔ Saved',
			'gtm_active_badge'            => 'GTM Active',
			'gtm_ga4_badge'               => 'GA4 Active',
			'gtm_desc_own'                => 'Enter your GTM Container ID or GA4 Measurement ID. The plugin injects the snippet in <code>&lt;head&gt;</code> at the correct position — after the GCM v2 consent default but before any other scripts.',
			'gtm_desc_detect'             => 'Fetches the page HTML source and scans for active Google Tags (GTM, GA4, UA). Only detects tags actually present in the rendered source — not plugin settings. GCM v2 consent mode fires automatically before the detected tag.',
			'gtm_desc_managed'            => 'Follow the step-by-step guide to create your GTM container and let this plugin inject and manage the snippet.',
		);
	}
	return $s[ $key ] ?? $key;
}
// ─────────────────────────────────────────────────────────────────────────────

// Admin footer — only on this plugin's own page.
add_filter(
	'admin_footer_text',
	function ( $text ) {
		$screen = get_current_screen();
		if ( $screen && 'toplevel_page_dc-sw-prefetch' === $screen->id ) {
			return sprintf(
			/* translators: %s: URL to DC Plugins GitHub organisation */
				__( 'More plugins by <a href="%s" target="_blank" rel="noopener">DC Plugins</a>', 'dc-sw-prefetch' ),
				'https://github.com/dc-plugins'
			);
		}
		return $text;
	}
);

// Add admin menu.
add_action( 'admin_menu', 'dc_swp_setup_menu' );
/**
 * Register the plugin admin menu page.
 *
 * @since 1.0.0
 * @return void
 */
function dc_swp_setup_menu() {
	add_menu_page(
		dc_swp_str( 'page_title' ),
		'SW Prefetch',
		'manage_options',
		'dc-sw-prefetch',
		'dc_swp_admin_page_html',
		'dashicons-performance'
	);
}

add_action( 'admin_enqueue_scripts', 'dc_swp_enqueue_admin_assets' );
/**
 * Enqueue admin page styles and register the admin script handle.
 *
 * @param string $hook Current admin page hook suffix.
 * @return void
 */
function dc_swp_enqueue_admin_assets( $hook ) {
	if ( 'toplevel_page_dc-sw-prefetch' !== $hook ) {
		return;
	}
	// phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- inline-only handle, no file to version.
	wp_register_style( 'dc-swp-admin', false, array(), null );
	wp_add_inline_style(
		'dc-swp-admin',
		"
    .pwa-cache-settings .form-table th {
        width: 250px;
        font-weight: 600;
    }
    .pwa-toggle {
        position: relative;
        display: inline-block;
        width: 60px;
        height: 34px;
    }
    .pwa-toggle input {
        opacity: 0;
        width: 0;
        height: 0;
    }
    .pwa-slider {
        position: absolute;
        cursor: pointer;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background-color: #ccc;
        transition: .4s;
        border-radius: 34px;
    }
    .pwa-slider:before {
        position: absolute;
        content: \"\";
        height: 26px;
        width: 26px;
        left: 4px;
        bottom: 4px;
        background-color: white;
        transition: .4s;
        border-radius: 50%;
    }
    input:checked + .pwa-slider {
        background-color: #2271b1;
    }
    input:checked + .pwa-slider:before {
        transform: translateX(26px);
    }
    /* ── Inline script blocks accordion ───────────────────────────────── */
    .dc-swp-blk-item { border:1px solid #dcdcde; border-radius:3px; margin-bottom:5px; background:#fff; }
    .dc-swp-blk-item.dc-swp-blk-disabled { opacity:.5; }
    .dc-swp-blk-hdr { display:flex; align-items:center; gap:8px; padding:8px 10px; cursor:pointer; user-select:none; background:#f6f7f7; border-radius:3px; }
    .dc-swp-blk-item.dc-swp-blk-open > .dc-swp-blk-hdr { border-radius:3px 3px 0 0; }
    .dc-swp-blk-hdr:hover { background:#f0f0f1; }
    .dc-swp-blk-chevron { font-size:16px; color:#787c82; flex-shrink:0; transition:transform .15s; }
    .dc-swp-blk-label { flex:1; font-weight:500; color:#1d2327; outline:none; min-width:0; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; cursor:text; }
    .dc-swp-blk-label:focus { outline:1px dashed #2271b1; padding:0 3px; border-radius:2px; white-space:pre; overflow:visible; }
    .dc-swp-blk-body { display:none; padding:10px; border-top:1px solid #dcdcde; background:#fcfcfd; }
    .dc-swp-blk-body textarea { font-family:Consolas,'Courier New',monospace; font-size:12px; line-height:1.5; }
    .dc-swp-blk-toggle { width:36px !important; height:22px !important; margin:0; flex-shrink:0; }
    .dc-swp-blk-toggle .pwa-slider:before { height:14px; width:14px; left:4px; bottom:4px; }
    .dc-swp-blk-toggle input:checked + .pwa-slider:before { transform:translateX(14px); }
    .dc-swp-add-area { border:1px dashed #c3c4c7; padding:14px 14px 10px; border-radius:3px; background:#f6f7f7; margin-top:4px; }
    .dc-swp-add-area h4 { margin:0 0 9px; font-size:13px; font-weight:600; color:#1d2327; }
    .dc-swp-add-area textarea { font-family:Consolas,'Courier New',monospace; font-size:12px; }
    /* ── Consent Architecture info panel ─────────────────────────────────── */
    .dc-swp-consent-info { border:1px solid #dcdcde; border-radius:3px; margin-top:10px; background:#fff; }
    .dc-swp-consent-info summary { padding:7px 11px; font-weight:600; cursor:pointer; color:#2271b1; font-size:12px; user-select:none; list-style:none; }
    .dc-swp-consent-info summary::-webkit-details-marker { display:none; }
    .dc-swp-consent-info summary::marker { display:none; }
    .dc-swp-consent-info summary::before { content:'\\25B6\\00A0'; font-size:9px; vertical-align:1px; }
    .dc-swp-consent-info[open] summary::before { content:'\\25BC\\00A0'; }
    .dc-swp-consent-info-body { padding:10px 13px 12px; border-top:1px solid #dcdcde; background:#fcfcfd; }
    .dc-swp-info-section { font-weight:600; font-size:11px; text-transform:uppercase; letter-spacing:.4px; color:#50575e; margin:12px 0 5px 0; }
    .dc-swp-info-section:first-child { margin-top:0; }
    .dc-swp-badges { display:flex; flex-wrap:wrap; gap:4px; margin:0 0 2px; }
    /* CSS badge — always the visible default; shields.io img overlays it once loaded */
    .dc-swp-badge { display:inline-flex; font-size:11px; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif; border-radius:3px; overflow:hidden; line-height:18px; height:18px; vertical-align:middle; white-space:nowrap; }
    .dc-swp-badge::before { content:attr(data-label); background:#555; color:#fff; padding:0 6px; display:flex; align-items:center; }
    .dc-swp-badge::after  { content:attr(data-msg);   color:#fff; padding:0 6px; display:flex; align-items:center; }
    .dc-swp-badge-blue::after  { background:#0075ca; }
    .dc-swp-badge-green::after { background:#3cb034; }
    .dc-swp-badge-amber::after { background:#e08a00; }
    .dc-swp-badge-red::after   { background:#e05d44; }
    .dc-swp-badge-meta::after  { background:#1877f2; }
    /* When the shields.io img loads successfully, hide the CSS pseudo-content and show the img */
    .dc-swp-badge img { display:none; height:18px; border:0; vertical-align:top; }
    .dc-swp-badge.dc-swp-loaded { display:inline-block; height:auto; overflow:visible; }
    .dc-swp-badge.dc-swp-loaded::before,
    .dc-swp-badge.dc-swp-loaded::after { display:none; }
    .dc-swp-badge.dc-swp-loaded img { display:block; }
    /* ── GTM mode panels ─────────────────────────────────────────────────── */
    .dc-swp-gtm-panel { margin-top:10px; padding:12px 14px; border:1px solid #dcdcde; border-radius:3px; background:#f9f9f9; }
    .dc-swp-gtm-valid   { color:#3cb034; font-weight:600; font-size:12px; margin-left:6px; }
    .dc-swp-gtm-invalid { color:#d63638; font-weight:600; font-size:12px; margin-left:6px; }
    /* ── GTM Onboarding Wizard ──────────────────────────────────────────── */
    .dc-swp-step-indicator { display:flex; gap:0; align-items:center; margin-bottom:16px; }
    .dc-swp-step-dot { width:28px; height:28px; border-radius:50%; background:#dcdcde; color:#50575e; font-size:11px; font-weight:700; display:flex; align-items:center; justify-content:center; flex-shrink:0; }
    .dc-swp-step-dot.active { background:#2271b1; color:#fff; }
    .dc-swp-step-dot.done   { background:#3cb034; color:#fff; }
    .dc-swp-step-connector  { flex:1; height:2px; background:#dcdcde; min-width:16px; }
    .dc-swp-wizard-step { display:none; }
    .dc-swp-wizard-step.dc-swp-active { display:block; }
    .dc-swp-wizard-nav { display:flex; gap:8px; align-items:center; margin-top:14px; }
    "
	);
	wp_enqueue_style( 'dc-swp-admin' );
	wp_register_script( 'dc-swp-admin-script', plugins_url( 'assets/js/admin.js', __FILE__ ), array( 'jquery' ), DC_SWP_VERSION, array( 'in_footer' => true ) );
	wp_enqueue_script( 'dc-swp-admin-script' );
}

// Register settings.
add_action( 'admin_init', 'dc_swp_register_settings' );

/**
 * Sanitize a raw JavaScript code block entered by an admin.
 *
 * The field intentionally stores raw JavaScript wrapped in <script>/<noscript>
 * tags; HTML-escaping would mangle JS operators. The only sanitization applied
 * is stripping PHP opening tags to prevent server-side execution if the value
 * is ever reflected outside the plugin's own echo context.
 *
 * @param string $code Raw JS code string supplied by an administrator.
 * @return string Sanitized code string.
 */
function dc_swp_sanitize_js_code( $code ) {
	// Strip PHP opening tags — prevents server-side execution if the stored value
	// is ever used in a PHP-parsed context outside this plugin's own output path.
	return preg_replace( '/<\?(?:php|=)?/i', '', (string) $code );
}

/**
 * Sanitize callback for the dc_swp_inline_scripts option.
 *
 * The value is a JSON-encoded array of inline script block objects managed by
 * the admin UI. Each block field is sanitized individually: id via sanitize_key(),
 * label via sanitize_text_field(), enabled as a boolean, and code via
 * dc_swp_sanitize_js_code() (admin-only JS content; capability-gated by manage_options).
 *
 * @param mixed $value Raw option value (JSON string).
 * @return string Sanitized JSON string, or empty string if invalid.
 */
function dc_swp_sanitize_inline_scripts_option( $value ) {
	if ( '' === $value || null === $value ) {
		return '';
	}
	$decoded = json_decode( $value, true );
	if ( ! is_array( $decoded ) ) {
		return '';
	}
	$sanitized = array();
	foreach ( $decoded as $blk ) {
		if ( ! is_array( $blk ) ) {
			continue;
		}
		$sanitized[] = array(
			'id'              => sanitize_key( $blk['id'] ?? '' ),
			'label'           => sanitize_text_field( $blk['label'] ?? '' ),
			'code'            => dc_swp_sanitize_js_code( $blk['code'] ?? '' ),
			'enabled'         => ! empty( $blk['enabled'] ),
			'force_partytown' => ! empty( $blk['force_partytown'] ),
			'category'        => in_array( $blk['category'] ?? '', array( 'marketing', 'statistics', 'statistics-anonymous', 'functional', 'preferences' ), true ) ? $blk['category'] : 'marketing',
		);
	}
	return wp_json_encode( $sanitized );
}

/**
 * Register all plugin settings with the WordPress Settings API.
 *
 * @since 1.0.0
 * @return void
 */
function dc_swp_register_settings() {
	register_setting( 'dc-sw-prefetch-settings', 'dc_swp_sw_enabled', array( 'sanitize_callback' => 'sanitize_text_field' ) );
	register_setting( 'dc-sw-prefetch-settings', 'dc_swp_footer_credit', array( 'sanitize_callback' => 'sanitize_text_field' ) );
	register_setting( 'dc-sw-prefetch-settings', 'dc_swp_partytown_scripts', array( 'sanitize_callback' => 'sanitize_textarea_field' ) );
	// Inline script blocks — admin-only JS content stored as JSON; validated via dc_swp_sanitize_inline_scripts_option.
	register_setting( 'dc-sw-prefetch-settings', 'dc_swp_inline_scripts', array( 'sanitize_callback' => 'dc_swp_sanitize_inline_scripts_option' ) );
	register_setting( 'dc-sw-prefetch-settings', 'dc_swp_coi_headers', array( 'sanitize_callback' => 'sanitize_text_field' ) );
	register_setting( 'dc-sw-prefetch-settings', 'dc_swp_consent_mode', array( 'sanitize_callback' => 'sanitize_text_field' ) );
	register_setting( 'dc-sw-prefetch-settings', 'dc_swp_url_passthrough', array( 'sanitize_callback' => 'sanitize_text_field' ) );
	register_setting( 'dc-sw-prefetch-settings', 'dc_swp_ads_data_redaction', array( 'sanitize_callback' => 'sanitize_text_field' ) );
	register_setting( 'dc-sw-prefetch-settings', 'dc_swp_meta_ldu', array( 'sanitize_callback' => 'sanitize_text_field' ) );
	register_setting( 'dc-sw-prefetch-settings', 'dc_swp_consent_gate', array( 'sanitize_callback' => 'sanitize_text_field' ) );
	register_setting( 'dc-sw-prefetch-settings', 'dc_swp_script_list_category', array( 'sanitize_callback' => 'sanitize_text_field' ) );
	register_setting( 'dc-sw-prefetch-settings', 'dc_swp_debug_mode', array( 'sanitize_callback' => 'sanitize_text_field' ) );
	register_setting( 'dc-sw-prefetch-settings', 'dc_swp_gtm_mode', array( 'sanitize_callback' => 'sanitize_text_field' ) );
	register_setting( 'dc-sw-prefetch-settings', 'dc_swp_gtm_id', array( 'sanitize_callback' => 'sanitize_text_field' ) );
}

// Admin page HTML.
/**
 * Output the admin settings page HTML.
 *
 * @since 1.0.0
 * @return void
 */
function dc_swp_admin_page_html() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return; }

	if ( isset( $_POST['dc_swp_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['dc_swp_nonce'] ) ), 'dc_swp_save_settings' ) ) {
		update_option( 'dc_swp_sw_enabled', isset( $_POST['dc_swp_sw_enabled'] ) ? 'yes' : 'no' );
		update_option( 'dc_swp_footer_credit', isset( $_POST['dc_swp_footer_credit'] ) ? 'yes' : 'no' );
		update_option( 'dc_swp_partytown_scripts', sanitize_textarea_field( wp_unslash( $_POST['dc_swp_partytown_scripts'] ?? '' ) ) );
		// Inline script blocks: decode the JS-managed JSON accordion payload.
		$raw_json_blocks  = wp_unslash( $_POST['dc_swp_inline_scripts_json'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON envelope; each field sanitized individually below.
		$sanitized_blocks = array();
		if ( '' !== $raw_json_blocks ) {
			$decoded_blocks = json_decode( $raw_json_blocks, true );
			if ( is_array( $decoded_blocks ) ) {
				foreach ( $decoded_blocks as $blk ) {
					if ( ! is_array( $blk ) ) {
						continue;
					}
					$sanitized_blocks[] = array(
						'id'              => sanitize_key( $blk['id'] ?? '' ),
						'label'           => sanitize_text_field( $blk['label'] ?? '' ),
						'code'            => dc_swp_sanitize_js_code( $blk['code'] ?? '' ),
						'enabled'         => ! empty( $blk['enabled'] ),
						'force_partytown' => ! empty( $blk['force_partytown'] ),
						'category'        => in_array( $blk['category'] ?? '', array( 'marketing', 'statistics', 'statistics-anonymous', 'functional', 'preferences' ), true ) ? $blk['category'] : 'marketing',
					);
				}
			}
		}
		update_option( 'dc_swp_inline_scripts', wp_json_encode( $sanitized_blocks ) );
		update_option( 'dc_swp_coi_headers', isset( $_POST['dc_swp_coi_headers'] ) ? 'yes' : 'no' );
		update_option( 'dc_swp_consent_mode', isset( $_POST['dc_swp_consent_mode'] ) ? 'yes' : 'no' );
		update_option( 'dc_swp_url_passthrough', isset( $_POST['dc_swp_url_passthrough'] ) ? 'yes' : 'no' );
		update_option( 'dc_swp_ads_data_redaction', isset( $_POST['dc_swp_ads_data_redaction'] ) ? 'yes' : 'no' );
		update_option( 'dc_swp_meta_ldu', isset( $_POST['dc_swp_meta_ldu'] ) ? 'yes' : 'no' );
		update_option( 'dc_swp_consent_gate', isset( $_POST['dc_swp_consent_gate'] ) ? 'yes' : 'no' );
		$_valid_cats = array( 'marketing', 'statistics', 'statistics-anonymous', 'functional', 'preferences' );
		$_cat_raw    = sanitize_text_field( wp_unslash( $_POST['dc_swp_script_list_category'] ?? 'marketing' ) );
		update_option( 'dc_swp_script_list_category', in_array( $_cat_raw, $_valid_cats, true ) ? $_cat_raw : 'marketing' );
		update_option( 'dc_swp_debug_mode', isset( $_POST['dc_swp_debug_mode'] ) ? 'yes' : 'no' );
		$_valid_gtm_modes = array( 'off', 'own', 'detect', 'managed' );
		$_gtm_mode_raw    = sanitize_text_field( wp_unslash( $_POST['dc_swp_gtm_mode'] ?? 'off' ) );
		update_option( 'dc_swp_gtm_mode', in_array( $_gtm_mode_raw, $_valid_gtm_modes, true ) ? $_gtm_mode_raw : 'off' );
		$_gtm_id_raw = sanitize_text_field( wp_unslash( $_POST['dc_swp_gtm_id'] ?? '' ) );
		// Accept empty string (disables injection) or a valid tag ID format.
		update_option( 'dc_swp_gtm_id', ( '' === $_gtm_id_raw || preg_match( '/^(GTM-[A-Z0-9]{4,10}|G-[A-Z0-9]{6,}|UA-\d{4,}-\d+)$/i', $_gtm_id_raw ) ) ? strtoupper( $_gtm_id_raw ) : '' );
		echo '<div class="notice notice-success"><p>' . esc_html( dc_swp_str( 'saved' ) ) . '</p></div>';
	}

	$sw_enabled         = get_option( 'dc_swp_sw_enabled', 'yes' ) === 'yes';
	$coi_headers        = get_option( 'dc_swp_coi_headers', 'no' ) === 'yes';
	$consent_mode       = get_option( 'dc_swp_consent_mode', 'no' ) === 'yes';
	$url_passthrough    = get_option( 'dc_swp_url_passthrough', 'no' ) === 'yes';
	$ads_data_redaction = get_option( 'dc_swp_ads_data_redaction', 'no' ) === 'yes';
	$meta_ldu           = get_option( 'dc_swp_meta_ldu', 'no' ) === 'yes';
	$consent_gate       = get_option( 'dc_swp_consent_gate', 'no' ) === 'yes';
	$script_list_cat    = get_option( 'dc_swp_script_list_category', 'marketing' );
	$debug_mode         = get_option( 'dc_swp_debug_mode', 'no' ) === 'yes';
	$gtm_mode           = get_option( 'dc_swp_gtm_mode', 'off' );
	$partytown_scripts  = get_option( 'dc_swp_partytown_scripts', '' );
	// Inline script blocks — decode JSON; auto-migrate legacy plain-text format.
	$inline_scripts_raw   = get_option( 'dc_swp_inline_scripts', '' );
	$inline_script_blocks = array();
	if ( '' !== $inline_scripts_raw ) {
		$decoded_blocks_raw = json_decode( $inline_scripts_raw, true );
		if ( is_array( $decoded_blocks_raw ) ) {
			$inline_script_blocks = $decoded_blocks_raw;
		} elseif ( preg_match( '/<script\b/i', $inline_scripts_raw ) ) {
			// Legacy plain-text format — auto-migrate to the new JSON structure.
			$inline_script_blocks = array(
				array(
					'id'      => 'block_' . substr( md5( $inline_scripts_raw ), 0, 8 ),
					'label'   => dc_swp_str( 'inline_scripts_imported' ),
					'code'    => $inline_scripts_raw,
					'enabled' => true,
				),
			);
			update_option( 'dc_swp_inline_scripts', wp_json_encode( $inline_script_blocks ) );
		}
	}
	$footer_credit = get_option( 'dc_swp_footer_credit', 'no' ) === 'yes';

	// Read vendored Partytown version from package.json using WP_Filesystem.
	$pkg_json   = plugin_dir_path( __FILE__ ) . 'package.json';
	$pt_version = 'unknown';
	if ( file_exists( $pkg_json ) ) {
		global $wp_filesystem;
		if ( empty( $wp_filesystem ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem();
		}
		$pkg_raw = $wp_filesystem->get_contents( $pkg_json );
		if ( false !== $pkg_raw ) {
			$pkg        = json_decode( $pkg_raw, true );
			$pt_version = $pkg['vendored']['@qwik.dev/partytown'] ?? 'unknown';
		}
	}
	?>
	<div class="wrap">
		<h1><?php echo esc_html( dc_swp_str( 'page_title' ) ); ?></h1>

		<div class="notice notice-info">
			<p><strong>ℹ️ <?php echo esc_html( dc_swp_str( 'info_title' ) ); ?></strong></p>
			<p><?php echo esc_html( dc_swp_str( 'info_body' ) ); ?></p>
			<p><?php echo esc_html( dc_swp_str( 'pt_version_label' ) ); ?>: <code><?php echo esc_html( $pt_version ); ?></code>
				&nbsp;—&nbsp;
				<a href="https://github.com/QwikDev/partytown/releases" target="_blank" rel="noopener">Changelog ↗</a></p>
		</div>

		<form method="post" action="" class="pwa-cache-settings">
			<?php wp_nonce_field( 'dc_swp_save_settings', 'dc_swp_nonce' ); ?>
			<table class="form-table">
				<tr valign="top">
					<th scope="row"><?php echo esc_html( dc_swp_str( 'sw_label' ) ); ?></th>
					<td>
						<label class="pwa-toggle">
							<input type="checkbox" name="dc_swp_sw_enabled" value="yes" <?php checked( $sw_enabled, true ); ?>>
							<span class="pwa-slider"></span>
						</label>
						<p class="description"><?php echo esc_html( dc_swp_str( 'sw_desc' ) ); ?></p>
					</td>
				</tr>
				<tr valign="top">
					<th scope="row"><?php echo esc_html( dc_swp_str( 'partytown_scripts_label' ) ); ?></th>
					<td>
						<textarea name="dc_swp_partytown_scripts" rows="5" class="large-text code"
							placeholder="e.g. analytics.ahrefs.com&#10;static.klaviyo.com&#10;fullstory.com"
						><?php echo esc_textarea( $partytown_scripts ); ?></textarea>
						<p class="description"><?php echo wp_kses_post( dc_swp_str( 'partytown_scripts_desc' ) ); ?></p>
						<p style="margin-top:8px;">
							<button type="button" id="dc-swp-autodetect-btn" class="button button-secondary">
								<?php echo esc_html( dc_swp_str( 'partytown_autodetect_btn' ) ); ?>
							</button>
							<span id="dc-swp-autodetect-spinner" class="spinner" style="float:none;margin-left:4px;display:none;"></span>
						</p>
						<div id="dc-swp-autodetect-results" style="display:none;margin-top:8px;padding:10px;background:#f9f9f9;border:1px solid #ddd;border-radius:3px;">
							<p style="margin:0 0 8px;"><strong><?php esc_html_e( 'Detected external scripts', 'dc-sw-prefetch' ); ?>:</strong></p>
							<div id="dc-swp-autodetect-list" style="margin-bottom:8px;"></div>
							<button type="button" id="dc-swp-add-selected" class="button button-primary" style="display:none;">
								<?php echo esc_html( dc_swp_str( 'partytown_autodetect_add' ) ); ?>
							</button>
						</div>
					</td>
				</tr>
				<tr valign="top">
					<th scope="row"><?php echo esc_html( dc_swp_str( 'inline_scripts_label' ) ); ?></th>
					<td>
						<input type="hidden" id="dc_swp_inline_scripts_json" name="dc_swp_inline_scripts_json" value="">
						<div id="dc-swp-block-list" style="margin-bottom:8px"></div>

						<div class="dc-swp-add-area">
							<h4><?php echo esc_html( dc_swp_str( 'inline_scripts_add_title' ) ); ?></h4>
							<input type="text" id="dc-swp-new-label"
								class="regular-text"
								style="width:100%;margin-bottom:8px;box-sizing:border-box"
								placeholder="<?php echo esc_attr( dc_swp_str( 'inline_scripts_lbl_ph' ) ); ?>">
							<textarea id="dc-swp-new-code" rows="8" class="large-text code"
								placeholder="&lt;!-- Paste the complete script block here, including &lt;script&gt; tags --&gt;"></textarea>
							<button type="button" id="dc-swp-add-block-btn" class="button button-secondary" style="margin-top:8px">
								<?php echo esc_html( dc_swp_str( 'inline_scripts_add_btn' ) ); ?>
							</button>
						</div>

						<p class="description" style="margin-top:8px"><?php echo wp_kses_post( dc_swp_str( 'inline_scripts_desc' ) ); ?></p>
					</td>
				</tr>
				<tr valign="top">
					<th scope="row"><?php echo esc_html( dc_swp_str( 'gtm_section_label' ) ); ?></th>
					<td>
						<!-- Hidden field — always submitted; JS syncs it from whichever panel is active -->
						<input type="hidden" name="dc_swp_gtm_id" id="dc_swp_gtm_id_field"
							value="<?php echo esc_attr( get_option( 'dc_swp_gtm_id', '' ) ); ?>">
						<fieldset>
						<?php
						$_gtm_modes = array(
							'off'     => dc_swp_str( 'gtm_mode_off' ),
							'own'     => dc_swp_str( 'gtm_mode_own' ),
							'detect'  => dc_swp_str( 'gtm_mode_detect' ),
							'managed' => dc_swp_str( 'gtm_mode_managed' ),
						);
						foreach ( $_gtm_modes as $_gv => $_gl ) :
							?>
						<label style="display:block;margin-bottom:6px">
							<input type="radio" name="dc_swp_gtm_mode" value="<?php echo esc_attr( $_gv ); ?>"
								<?php checked( $gtm_mode, $_gv ); ?>>
							<?php echo esc_html( $_gl ); ?>
						</label>
						<?php endforeach; ?>
						</fieldset>

						<!-- Panel: own -->
						<div id="dc-swp-gtm-panel-own" class="dc-swp-gtm-panel" <?php echo 'own' !== $gtm_mode ? 'style="display:none"' : ''; ?>>
							<input type="text" id="dc-swp-gtm-id-own"
								class="regular-text" style="font-family:monospace"
								value="<?php echo esc_attr( get_option( 'dc_swp_gtm_id', '' ) ); ?>"
								placeholder="<?php echo esc_attr( dc_swp_str( 'gtm_id_placeholder' ) ); ?>">
							<span id="dc-swp-gtm-id-status"></span>
							<p class="description" style="margin-top:6px"><?php echo wp_kses_post( dc_swp_str( 'gtm_desc_own' ) ); ?></p>
						</div>

						<!-- Panel: detect -->
						<div id="dc-swp-gtm-panel-detect" class="dc-swp-gtm-panel"
							data-saved-id="<?php echo esc_attr( get_option( 'dc_swp_gtm_id', '' ) ); ?>"
							<?php echo 'detect' !== $gtm_mode ? 'style="display:none"' : ''; ?>>
							<button type="button" id="dc-swp-gtm-detect-btn" class="button button-secondary">
								<?php echo esc_html( dc_swp_str( 'gtm_detect_btn' ) ); ?>
							</button>
							<span id="dc-swp-gtm-detect-spinner" class="spinner" style="float:none;margin-left:4px;display:none;"></span>
							<div id="dc-swp-gtm-detect-result" style="margin-top:8px"></div>
							<p class="description" style="margin-top:6px"><?php echo wp_kses_post( dc_swp_str( 'gtm_desc_detect' ) ); ?></p>
						</div>

						<!-- Panel: managed (wizard) -->
						<div id="dc-swp-gtm-panel-managed" class="dc-swp-gtm-panel" <?php echo 'managed' !== $gtm_mode ? 'style="display:none"' : ''; ?>>
							<div class="dc-swp-step-indicator">
							<?php for ( $_ws = 1; $_ws <= 4; $_ws++ ) : ?>
								<?php
								if ( $_ws > 1 ) :
									?>
									<span class="dc-swp-step-connector"></span><?php endif; ?>
									<span class="dc-swp-step-dot" data-step="<?php echo (int) $_ws; ?>"><?php echo (int) $_ws; ?></span>
							<?php endfor; ?>
							</div>
							<?php
							$_wiz_steps = array(
								1 => array( dc_swp_str( 'gtm_wizard_step1_title' ), dc_swp_str( 'gtm_wizard_step1_body' ) ),
								2 => array( dc_swp_str( 'gtm_wizard_step2_title' ), dc_swp_str( 'gtm_wizard_step2_body' ) ),
								3 => array( dc_swp_str( 'gtm_wizard_step3_title' ), dc_swp_str( 'gtm_wizard_step3_body' ) ),
								4 => array( dc_swp_str( 'gtm_wizard_step4_title' ), dc_swp_str( 'gtm_wizard_step4_body' ) ),
							);
							foreach ( $_wiz_steps as $_sn => $_wiz_step ) :
								$_st = $_wiz_step[0];
								$_sb = $_wiz_step[1];
								?>
								<div id="dc-swp-wizard-step-<?php echo (int) $_sn; ?>" class="dc-swp-wizard-step">
								<h4 style="margin-top:0"><?php echo esc_html( $_st ); ?></h4>
								<p><?php echo wp_kses_post( $_sb ); ?></p>
								<?php if ( 2 === $_sn ) : ?>
								<div style="margin:10px 0">
									<input type="text" id="dc-swp-gtm-wizard-id"
										class="regular-text" style="font-family:monospace"
										value="<?php echo esc_attr( get_option( 'dc_swp_gtm_id', '' ) ); ?>"
										placeholder="<?php echo esc_attr( dc_swp_str( 'gtm_id_placeholder' ) ); ?>">
									<span id="dc-swp-gtm-wizard-status"></span>
								</div>
								<?php endif; ?>
								<?php if ( 4 === $_sn ) : ?>
								<div id="dc-swp-wizard-summary" style="margin:10px 0;padding:10px;background:#f0f7f0;border:1px solid #3cb034;border-radius:3px;display:none">
									<strong><?php echo esc_html( dc_swp_str( 'gtm_active_badge' ) ); ?>:</strong> <code id="dc-swp-wizard-summary-id"></code>
								</div>
								<?php endif; ?>
								<div class="dc-swp-wizard-nav">
									<?php if ( $_sn > 1 ) : ?>
									<button type="button" class="button dc-swp-wizard-btn" data-dir="prev" data-step="<?php echo (int) $_sn; ?>">
										<?php echo esc_html( dc_swp_str( 'gtm_wizard_prev' ) ); ?>
									</button>
									<?php endif; ?>
									<?php if ( $_sn < 4 ) : ?>
									<button type="button" class="button button-primary dc-swp-wizard-btn"
										data-dir="next" data-step="<?php echo (int) $_sn; ?>"
										<?php echo 2 === $_sn ? 'id="dc-swp-wizard-step2-next" disabled' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fully static HTML attribute string. ?>>
										<?php echo esc_html( dc_swp_str( 'gtm_wizard_next' ) ); ?>
									</button>
									<?php else : ?>
									<button type="button" class="button button-primary" id="dc-swp-wizard-complete">
										<?php echo esc_html( dc_swp_str( 'gtm_wizard_done' ) ); ?>
									</button>
									<?php endif; ?>
								</div>
							</div>
							<?php endforeach; ?>
							<p class="description" style="margin-top:10px"><?php echo wp_kses_post( dc_swp_str( 'gtm_desc_managed' ) ); ?></p>
						</div>
					</td>
				</tr>
				<tr valign="top" id="dc-swp-consent-mode-row"<?php echo 'off' === $gtm_mode ? ' style="display:none"' : ''; ?>>
					<th scope="row"><?php echo esc_html( dc_swp_str( 'consent_mode_label' ) ); ?></th>
					<td>
						<label class="pwa-toggle">
							<input type="checkbox" name="dc_swp_consent_mode" value="yes" <?php checked( $consent_mode, true ); ?>>
							<span class="pwa-slider"></span>
						</label>
						<p class="description"><?php echo wp_kses_post( dc_swp_str( 'consent_mode_desc' ) ); ?></p>
					<div id="dc-swp-gcm-notices"></div>
					<?php
					// ── Consent Architecture info panel ─────────────────────────────────
					// CSS badges are always rendered as the fallback (pure CSS ::before/::after).
					// The shields.io <img> fires onload to swap in the real badge when available;
					// offline / firewalled environments automatically keep the CSS version.
					$_si = 'https://img.shields.io/badge/';
					$_sq = '?style=flat-square';
					// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- badge HTML built with fully-escaped values; onload handler is static JS, no user data.
					$_badge = static function ( $label, $msg, $col, $url ) {
						$onload = "this.closest('.dc-swp-badge').classList.add('dc-swp-loaded')";
						return '<span class="dc-swp-badge dc-swp-badge-' . esc_attr( $col ) . '" '
							. 'data-label="' . esc_attr( $label ) . '" '
							. 'data-msg="' . esc_attr( $msg ) . '">'
							. '<img src="' . esc_url( $url ) . '" '
							. 'alt="' . esc_attr( $label . ' ' . $msg ) . '" '
							. 'loading="lazy" decoding="async" '
							. 'onload="' . esc_attr( $onload ) . '">'
							. '</span>';
					};
					$_gcm   = array(
						array( 'Google Tag Manager', 'GCM v2', 'blue', $_si . 'Google%20Tag%20Manager-GCM%20v2-0075ca' . $_sq ),
						array( 'Google Analytics', 'GCM v2', 'blue', $_si . 'Google%20Analytics-GCM%20v2-0075ca' . $_sq ),
						array( 'Hotjar', 'GCM v2', 'blue', $_si . 'Hotjar-GCM%20v2-0075ca' . $_sq ),
						array( 'MS Clarity', 'GCM v2', 'blue', $_si . 'MS%20Clarity-GCM%20v2-0075ca' . $_sq ),
						array( 'LinkedIn Insight', 'GCM v2', 'blue', $_si . 'LinkedIn%20Insight-GCM%20v2-0075ca' . $_sq ),
						array( 'TikTok Pixel', 'GCM v2', 'blue', $_si . 'TikTok%20Pixel-GCM%20v2-0075ca' . $_sq ),
					);
					// phpcs:enable
					?>
					<details class="dc-swp-consent-info">
						<summary><?php echo esc_html( dc_swp_str( 'consent_info_toggle' ) ); ?></summary>
						<div class="dc-swp-consent-info-body">

							<p class="dc-swp-info-section"><?php echo esc_html( dc_swp_str( 'consent_info_services_title' ) ); ?></p>
							<p class="description" style="margin-bottom:6px"><?php echo esc_html( dc_swp_str( 'consent_info_services_desc' ) ); ?></p>
							<div class="dc-swp-badges">
								<?php
								foreach ( $_gcm as $_b ) {
									echo $_badge( $_b[0], $_b[1], $_b[2], $_b[3] ); } // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped 
								?>
							</div>

							<p class="dc-swp-info-section"><?php echo esc_html( dc_swp_str( 'consent_info_meta_title' ) ); ?></p>
							<p class="description" style="margin-bottom:6px"><?php echo esc_html( dc_swp_str( 'consent_info_meta_desc' ) ); ?></p>
							<div class="dc-swp-badges">
								<?php echo $_badge( 'Meta Pixel', 'LDU API', 'meta', $_si . 'Meta%20Pixel-LDU%20API-1877f2' . $_sq ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							</div>

						</div>
					</details>
					</td>
				</tr>
				<tr valign="top">					<th scope="row"><?php echo esc_html( dc_swp_str( 'url_passthrough_label' ) ); ?></th>
					<td>
						<label class="pwa-toggle">
							<input type="checkbox" name="dc_swp_url_passthrough" value="yes" <?php checked( $url_passthrough, true ); ?>>
							<span class="pwa-slider"></span>
						</label>
						<p class="description"><?php echo wp_kses_post( dc_swp_str( 'url_passthrough_desc' ) ); ?></p>
					</td>
				</tr>
				<tr valign="top">
					<th scope="row"><?php echo esc_html( dc_swp_str( 'ads_data_redaction_label' ) ); ?></th>
					<td>
						<label class="pwa-toggle">
							<input type="checkbox" name="dc_swp_ads_data_redaction" value="yes" <?php checked( $ads_data_redaction, true ); ?>>
							<span class="pwa-slider"></span>
						</label>
						<p class="description"><?php echo wp_kses_post( dc_swp_str( 'ads_data_redaction_desc' ) ); ?></p>
					</td>
				</tr>
				<tr valign="top">					<th scope="row"><?php echo esc_html( dc_swp_str( 'meta_ldu_label' ) ); ?></th>
					<td>
						<label class="pwa-toggle">
							<input type="checkbox" name="dc_swp_meta_ldu" value="yes" <?php checked( $meta_ldu, true ); ?>>
							<span class="pwa-slider"></span>
						</label>
						<p class="description"><?php echo wp_kses_post( dc_swp_str( 'meta_ldu_desc' ) ); ?></p>
					</td>
				</tr>
				<tr valign="top">
					<th scope="row"><?php echo esc_html( dc_swp_str( 'consent_gate_label' ) ); ?></th>
					<td>
						<label class="pwa-toggle">
							<input type="checkbox" id="dc_swp_consent_gate" name="dc_swp_consent_gate" value="yes" <?php checked( $consent_gate, true ); ?>>
							<span class="pwa-slider"></span>
						</label>
						<p class="description"><?php echo wp_kses_post( dc_swp_str( 'consent_gate_desc' ) ); ?></p>
						<?php if ( $consent_gate && ! function_exists( 'wp_has_consent' ) ) : ?>
							<div class="notice notice-warning inline" style="margin-top:8px;padding:8px 12px">
								<p><?php echo esc_html( dc_swp_str( 'consent_gate_notice' ) ); ?></p>
							</div>
						<?php endif; ?>
					</td>
				</tr>
				<tr valign="top" id="dc-swp-script-list-cat-row"<?php echo ! $consent_gate ? ' style="display:none"' : ''; ?>>
					<th scope="row"><?php echo esc_html( dc_swp_str( 'script_list_category_label' ) ); ?></th>
					<td>
						<select name="dc_swp_script_list_category">
							<?php
							$_cat_options = array( 'marketing', 'statistics', 'statistics-anonymous', 'functional', 'preferences' );
							foreach ( $_cat_options as $_co ) {
								printf(
									'<option value="%s"%s>%s</option>',
									esc_attr( $_co ),
									selected( $script_list_cat, $_co, false ),
									esc_html( ucfirst( $_co ) )
								);
							}
							?>
						</select>
						<p class="description"><?php echo esc_html( dc_swp_str( 'script_list_category_desc' ) ); ?></p>
					</td>
				</tr>
				<tr valign="top">
					<th scope="row"><?php echo esc_html( dc_swp_str( 'coi_label' ) ); ?></th>
					<td>
						<label class="pwa-toggle">
							<input type="checkbox" name="dc_swp_coi_headers" value="yes" <?php checked( $coi_headers, true ); ?>>
							<span class="pwa-slider"></span>
						</label>
						<p class="description"><?php echo wp_kses_post( dc_swp_str( 'coi_desc' ) ); ?></p>
					</td>
				</tr>
				<tr valign="top">
					<th scope="row"><?php echo esc_html( dc_swp_str( 'debug_label' ) ); ?></th>
					<td>
						<label class="pwa-toggle">
							<input type="checkbox" name="dc_swp_debug_mode" value="yes" <?php checked( $debug_mode, true ); ?>>
							<span class="pwa-slider"></span>
						</label>
						<p class="description"><?php echo wp_kses_post( dc_swp_str( 'debug_desc' ) ); ?></p>
					</td>
				</tr>
			</table>

			<h2><?php echo esc_html( dc_swp_str( 'strategy_title' ) ); ?></h2>
			<table class="form-table">
				<tr>
					<th scope="row"><?php echo esc_html( dc_swp_str( 'html_label' ) ); ?></th>
					<td>
						<p><strong><?php echo esc_html( dc_swp_str( 'html_val' ) ); ?></strong></p>
						<p class="description"><?php echo esc_html( dc_swp_str( 'html_desc' ) ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php echo esc_html( dc_swp_str( 'static_label' ) ); ?></th>
					<td>
						<p><strong><?php echo esc_html( dc_swp_str( 'static_val' ) ); ?></strong></p>
						<p class="description"><?php echo esc_html( dc_swp_str( 'static_desc' ) ); ?></p>
					</td>
				</tr>
			</table>

			<h2><?php echo esc_html( dc_swp_str( 'benefits_title' ) ); ?></h2>
			<ul style="list-style: disc; margin-left: 20px;">
				<?php foreach ( array( 'benefit_1', 'benefit_2', 'benefit_3', 'benefit_4', 'benefit_5', 'benefit_6', 'benefit_7', 'benefit_8' ) as $b ) : ?>
					<li>✅ <?php echo esc_html( dc_swp_str( $b ) ); ?></li>
				<?php endforeach; ?>
			</ul>

			<table class="form-table">
				<tr valign="top">
					<th scope="row"><?php echo esc_html( dc_swp_str( 'credit_label' ) ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="dc_swp_footer_credit" value="yes" <?php checked( $footer_credit, true ); ?>>
							<?php echo esc_html( dc_swp_str( 'credit_checkbox' ) ); ?>
						</label>
						<p class="description"><?php echo wp_kses_post( dc_swp_str( 'credit_desc' ) ); ?></p>
					</td>
				</tr>
			</table>

			<?php submit_button( dc_swp_str( 'save_button' ) ); ?>
		</form>
	</div>

	<?php
	wp_localize_script(
		'dc-swp-admin-script',
		'dcSwpAdminData',
		array(
			'nonce'              => wp_create_nonce( 'dc_swp_detect_nonce' ),
			'noScriptsMsg'       => dc_swp_str( 'partytown_autodetect_none' ),
			'unknownMsg'         => dc_swp_str( 'partytown_autodetect_warn' ),
			'knownMsg'           => dc_swp_str( 'partytown_autodetect_known' ),
			'noBlocksMsg'        => dc_swp_str( 'inline_scripts_empty' ),
			'delMsg'             => dc_swp_str( 'inline_scripts_del_confirm' ),
			'blocks'             => $inline_script_blocks,
			'knownServices'      => dc_swp_get_known_services(),
			'badgeSupported'     => dc_swp_str( 'badge_supported' ),
			'badgeUnsupported'   => dc_swp_str( 'badge_unsupported' ),
			'forcePtLabel'       => dc_swp_str( 'force_pt_label' ),
			'forcePtNotice'      => dc_swp_str( 'force_pt_notice' ),
			'blockCategoryLabel' => dc_swp_str( 'block_category_label' ),
			'consentCategories'  => array( 'marketing', 'statistics', 'statistics-anonymous', 'functional', 'preferences' ),
			'gtm'                => array(
				'valid'        => dc_swp_str( 'gtm_id_valid' ),
				'invalid'      => dc_swp_str( 'gtm_id_invalid' ),
				'detected'     => dc_swp_str( 'gtm_detect_found' ),
				'use'          => dc_swp_str( 'gtm_detect_use' ),
				'none'         => dc_swp_str( 'gtm_detect_none' ),
				'autoSwitched' => dc_swp_str( 'gtm_detect_auto_switched' ),
				'willBeUsed'   => dc_swp_str( 'gtm_detect_will_use' ),
				'active'       => dc_swp_str( 'gtm_detect_active' ),
				'saved'        => dc_swp_str( 'gtm_wizard_saved' ),
			),
			'gcm'                => array(
				'checking'          => dc_swp_str( 'gcm_conflict_checking' ),
				'conflictTitle'     => dc_swp_str( 'gcm_conflict_title' ),
				'conflictBody'      => dc_swp_str( 'gcm_conflict_body' ),
				'noConsentApiTitle' => dc_swp_str( 'gcm_no_consent_api_title' ),
				'noConsentApiBody'  => dc_swp_str( 'gcm_no_consent_api_body' ),
				'noConsentApiLink'  => dc_swp_str( 'gcm_no_consent_api_link' ),
				'wpConsentApiUrl'   => admin_url( 'plugin-install.php?s=wp-consent-api&tab=search&type=term' ),
			),
		)
	);
}

// ============================================================
// AJAX — Auto-detect third-party scripts on the homepage
// ============================================================

add_action( 'wp_ajax_dc_swp_detect_scripts', 'dc_swp_ajax_detect_scripts' );
/**
 * AJAX handler: detect third-party scripts on the homepage for the admin UI.
 *
 * @since 1.0.0
 * @return void
 */
function dc_swp_ajax_detect_scripts() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( 'Unauthorized' );
	}
	if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'dc_swp_detect_nonce' ) ) {
		wp_send_json_error( 'Invalid nonce' );
	}

	$response = wp_remote_get(
		home_url( '/' ),
		array(
			'timeout'    => 15,
			'sslverify'  => true,
			'user-agent' => 'Mozilla/5.0 (DCSwPrefetch/1.0; Auto-Detect)',
		)
	);
	if ( is_wp_error( $response ) ) {
		wp_send_json_error( $response->get_error_message() );
	}

	$body      = wp_remote_retrieve_body( $response );
	$site_host = wp_parse_url( home_url(), PHP_URL_HOST );

	preg_match_all( '/<script[^>]+\bsrc=["\'](https?:[^"\']+|[\/][^"\']+)["\']/i', $body, $matches );

	// Patterns for services listed on the Partytown common-services page.
	// phpcs:ignore Squiz.Commenting.InlineComment.InvalidEndChar
	$known_patterns = dc_swp_get_known_services();

	// Hosts with dedicated plugin panels — excluded from generic autodetect results.
	$dedicated_hosts = array( 'googletagmanager.com', 'connect.facebook.net' );

	// Already-configured hosts (include list + Script Block) — never suggest these.
	$already_configured = dc_swp_get_proxy_allowed_hosts();

	$seen    = array();
	$scripts = array();
	foreach ( (array) $matches[1] as $src ) {
		if ( str_starts_with( $src, '//' ) ) {
			$src = 'https:' . $src;
		}
		if ( str_starts_with( $src, '/' ) ) {
			continue; // On-site relative URL — skip.
		}
		$parsed = wp_parse_url( $src );
		if ( empty( $parsed['host'] ) || $parsed['host'] === $site_host ) {
			continue;
		}
		$host = strtolower( $parsed['host'] );
		if ( isset( $seen[ $host ] ) ) {
			continue; // Deduplicate by hostname.
		}
		$seen[ $host ] = true;

		// Skip hosts that have a dedicated plugin panel (GTM, Facebook Pixel).
		$is_dedicated = false;
		foreach ( $dedicated_hosts as $d_host ) {
			if ( str_contains( $host, $d_host ) ) {
				$is_dedicated = true;
				break;
			}
		}
		if ( $is_dedicated ) {
			continue;
		}

		// Skip hosts already in the include list or the Script Block.
		$already = false;
		foreach ( $already_configured as $configured_host ) {
			if ( str_contains( $host, $configured_host ) || str_contains( $configured_host, $host ) ) {
				$already = true;
				break;
			}
		}
		if ( $already ) {
			continue;
		}

		$is_known = false;
		foreach ( $known_patterns as $pat ) {
			if ( str_contains( $host, $pat ) || str_contains( $pat, $host ) ) {
				$is_known = true;
				break;
			}
		}
		$scripts[] = array(
			'host'  => $host,
			'known' => $is_known,
		);
	}

	wp_send_json_success( array( 'scripts' => $scripts ) );
}

// ============================================================
// AJAX — Check for conflicting GCM v2 stubs on the homepage
// ============================================================

add_action( 'wp_ajax_dc_swp_check_gcm_conflict', 'dc_swp_ajax_check_gcm_conflict' );
/**
 * AJAX handler: fetch the homepage and detect any GCM v2 default stub
 * not produced by this plugin.
 *
 * Strategy: scan for gtag('consent','default',...) and exclude matches
 * that contain 'default_consent' nearby — that string is the exclusive
 * fingerprint of our own stub (dataLayer.push({event:'default_consent'})).
 * Also reports whether the WP Consent API plugin is active so the admin
 * UI can prompt the user to install it if missing.
 *
 * @since 1.9.0
 * @return void
 */
function dc_swp_ajax_check_gcm_conflict() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( 'Unauthorized', 403 );
	}
	if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'dc_swp_detect_nonce' ) ) {
		wp_send_json_error( 'Invalid nonce' );
	}

	$response = wp_remote_get(
		home_url( '/' ),
		array(
			'timeout'    => 15,
			'sslverify'  => true,
			'user-agent' => 'Mozilla/5.0 (DCSwPrefetch/1.0; GCM-Conflict-Check)',
		)
	);

	if ( is_wp_error( $response ) ) {
		wp_send_json_error( $response->get_error_message() );
	}

	$body         = wp_remote_retrieve_body( $response );
	$has_conflict = false;

	/*
	 * Find every gtag('consent','default',...) call in the page source.
	 * Our own stub always emits `dataLayer.push({event:'default_consent'})`
	 * immediately after — use that as the exclusion fingerprint so we never
	 * flag our own output as a conflict.
	 */
	if ( preg_match_all( "/gtag\s*\(\s*['\"]consent['\"]\s*,\s*['\"]default['\"]/i", $body, $m, PREG_OFFSET_CAPTURE ) ) {
		foreach ( $m[0] as $hit ) {
			// Capture 850 chars starting 50 chars before the match to cover the full stub.
			$snippet = substr( $body, max( 0, $hit[1] - 50 ), 850 );
			// Absence of our fingerprint → foreign stub → conflict.
			if ( false === strpos( $snippet, 'default_consent' ) ) {
				$has_conflict = true;
				break;
			}
		}
	}

	wp_send_json_success(
		array(
			'conflict'       => $has_conflict,
			'wp_consent_api' => function_exists( 'wp_has_consent' ),
		)
	);
}
