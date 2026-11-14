<?php
$gridMax = (int) setting('jobs.grid_max', 6);
$defaultProvider = (string) setting('providers.default', 'google_places');
if (!isset($providers[$defaultProvider])) {
    $defaultProvider = (string) array_key_first($providers ?: ['' => '']);
}
$caps = ['google_places' => 60, 'serpapi' => 120];
$oldBool = static fn (string $k, bool $def) => old($k, null) === null ? $def : filter_var(old($k), FILTER_VALIDATE_BOOL);
$init = [
    'keywords' => (string) old('keywords', ''),
    'mode' => (string) old('location_mode', 'city'),
    'city' => (string) old('city', ''),
    'cityLat' => (string) old('city_lat', ''),
    'cityLon' => (string) old('city_lon', ''),
    'lat' => (string) old('lat', ''),
    'lon' => (string) old('lon', ''),
    'radius' => (string) old('radius_km', '5'),
    'grid' => (int) old('grid', 1),
    'deep' => $oldBool('deep_scan', false),
    'provider' => (string) old('provider', $defaultProvider),
    'perSearch' => (int) old('max_per_search', 60),
    'caps' => $caps,
    'noWebsite' => $oldBool('only_without_website', false),
    'types' => $types,
    'citiesUrl' => url('presets/cities'),
];
$err = errors();
?>
<?php if (!$providers): ?>
    <div class="card p-8 text-center">
        <div class="mx-auto grid h-12 w-12 place-items-center rounded-2xl bg-amber-100 text-amber-600"><?= icon('key', 'w-6 h-6') ?></div>
        <h2 class="mt-3 font-semibold text-slate-900">No data provider is configured</h2>
        <p class="mt-1 text-sm text-slate-600">An administrator needs to add a Google Places API or SerpApi key before searches can run.</p>
        <?php if (can('providers.manage')): ?><a class="btn-primary mt-5" href="<?= e(url('settings/providers')) ?>">Configure providers</a><?php endif; ?>
    </div>
<?php return; endif; ?>

<script type="application/json" id="job-form-data"><?= js($init) ?></script>
<form method="post" action="<?= e(url('jobs')) ?>" x-data="jobForm" class="grid gap-6 lg:grid-cols-3" novalidate>
    <?= csrf_field() ?>
    <div class="space-y-6 lg:col-span-2">

        <!-- 1. What -->
        <section class="card p-5 sm:p-6">
            <div class="flex items-center gap-3">
                <span class="step-dot">1</span>
                <div><h2 class="section-title">What are you looking for?</h2><p class="text-xs text-slate-500">One business type or search phrase per line.</p></div>
            </div>
            <div class="mt-5 grid gap-4">
                <div>
                    <label class="label" for="keywords">Keywords</label>
                    <textarea id="keywords" name="keywords" rows="4" x-model="keywords" class="input font-mono text-sm" placeholder="dentists&#10;orthodontists&#10;dental clinics"></textarea>
                    <?= field_error('keywords') ?>
                    <div class="mt-3">
                        <input type="search" x-model="typeQuery" class="input input-sm" placeholder="Browse <?= count($types) ?> business type presets…" aria-label="Filter business types">
                        <div class="mt-2 flex max-h-28 flex-wrap gap-1.5 overflow-y-auto">
                            <template x-for="t in filteredTypes" :key="t">
                                <button type="button" class="chip" @click="addKeyword(t)" x-text="t"></button>
                            </template>
                        </div>
                    </div>
                </div>
                <div>
                    <label class="label" for="name">Job name <span class="font-normal text-slate-400">(optional)</span></label>
                    <input id="name" name="name" class="input" maxlength="200" value="<?= e(old('name')) ?>" placeholder="e.g. Dentists — Pune Q4">
                    <?= field_error('name') ?>
                </div>
            </div>
        </section>

        <!-- 2. Where -->
        <section class="card p-5 sm:p-6">
            <div class="flex items-center gap-3">
                <span class="step-dot">2</span>
                <div><h2 class="section-title">Where?</h2><p class="text-xs text-slate-500">Pick a city, drop coordinates, or let the keywords carry the place.</p></div>
            </div>
            <input type="hidden" name="location_mode" :value="mode">
            <div class="mt-5 grid grid-cols-3 gap-1 rounded-xl bg-slate-100 p-1 text-sm font-medium">
                <button type="button" class="seg" :class="mode === 'city' && 'seg-on'" @click="mode = 'city'">City</button>
                <button type="button" class="seg" :class="mode === 'coords' && 'seg-on'" @click="mode = 'coords'">Coordinates</button>
                <button type="button" class="seg" :class="mode === 'none' && 'seg-on'" @click="mode = 'none'">Anywhere</button>
            </div>

            <div x-show="mode === 'city'" class="mt-4">
                <label class="label" for="city">City or area</label>
                <div class="relative" @click.outside="suggestions = []">
                    <input id="city" name="city" x-model="city" @input.debounce.250ms="lookupCity()" @keydown.down.prevent="move(1)" @keydown.up.prevent="move(-1)" @keydown.enter="enter($event)"
                           autocomplete="off" class="input" placeholder="Start typing: Pune, Austin TX, Berlin…">
                    <input type="hidden" name="city_lat" :value="cityLat"><input type="hidden" name="city_lon" :value="cityLon"><input type="hidden" name="city_country" :value="cityCountry">
                    <ul x-show="suggestions.length" x-cloak class="absolute z-20 mt-1 max-h-64 w-full overflow-auto rounded-xl border border-slate-200 bg-white p-1 shadow-xl">
                        <template x-for="(s, i) in suggestions" :key="s.label + i">
                            <li><button type="button" class="flex w-full items-center gap-2 rounded-lg px-3 py-2 text-left text-sm" :class="i === hi ? 'bg-indigo-50 text-indigo-700' : 'hover:bg-slate-50'" @click="pick(s)">
                                <?= icon('map-pin', 'w-4 h-4 shrink-0 text-slate-400') ?><span x-text="s.label" class="truncate"></span></button></li>
                        </template>
                    </ul>
                </div>
                <p class="mt-1 text-xs text-slate-500" x-show="cityLat" x-cloak>📍 <span x-text="cityLat + ', ' + cityLon"></span></p>
                <?= field_error('city') ?>
            </div>

            <div x-show="mode === 'coords'" x-cloak class="mt-4 grid gap-4 sm:grid-cols-3">
                <div><label class="label" for="lat">Latitude</label><input id="lat" name="lat" x-model="lat" inputmode="decimal" class="input" placeholder="18.5204"></div>
                <div><label class="label" for="lon">Longitude</label><input id="lon" name="lon" x-model="lon" inputmode="decimal" class="input" placeholder="73.8567"></div>
                <div><label class="label" for="radius_km">Radius (km)</label><input id="radius_km" name="radius_km" x-model="radius" type="number" min="0.5" max="50" step="0.5" class="input"></div>
                <div class="sm:col-span-3 flex items-center gap-3">
                    <button type="button" class="btn-secondary" @click="locate()"><?= icon('crosshair', 'w-4 h-4') ?> Use my location</button>
                    <span class="text-xs text-slate-500" x-text="geoMsg"></span>
                </div>
                <div class="sm:col-span-3"><?= field_error('lat') ?></div>
            </div>

            <div x-show="mode === 'none'" x-cloak class="mt-4 rounded-xl bg-slate-50 p-4 text-sm text-slate-600">
                Write the place into each keyword, e.g. <code class="font-mono text-slate-800">dentists in Pune</code>. Grid and deep scan need a city or coordinates.
            </div>

            <div x-show="mode !== 'none'" class="mt-5 grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="label" for="grid">Coverage grid</label>
                    <select id="grid" name="grid" x-model.number="grid" class="input">
                        <?php for ($g = 1; $g <= $gridMax; $g++): ?>
                            <option value="<?= $g ?>"><?= $g === 1 ? 'Single search (fastest)' : "$g × $g tiles (" . ($g * $g) . ' searches per keyword)' ?></option>
                        <?php endfor; ?>
                    </select>
                    <?= field_error('grid') ?>
                    <p class="mt-1 text-xs text-slate-500">Providers cap results per search. Tiles cover a whole city.</p>
                </div>
                <label class="toggle-card">
                    <input type="hidden" name="deep_scan" value="0">
                    <input type="checkbox" name="deep_scan" value="1" x-model="deep" class="peer sr-only">
                    <span class="toggle"></span>
                    <span><span class="block text-sm font-medium text-slate-900">Deep scan</span><span class="block text-xs text-slate-500">Split tiles that hit the cap into 4 smaller ones (up to 2 levels). Slower, finds more.</span></span>
                </label>
            </div>
        </section>

        <!-- 3. Enrichment -->
        <section class="card p-5 sm:p-6">
            <div class="flex items-center gap-3">
                <span class="step-dot">3</span>
                <div><h2 class="section-title">Enrich each lead</h2><p class="text-xs text-slate-500">We visit each business website (homepage + contact page) safely from your server.</p></div>
            </div>
            <div class="mt-5 grid gap-3 sm:grid-cols-2">
                <?php foreach ([
                    ['emails', 'Find emails', 'Up to 3 per business, junk and no-reply filtered', true],
                    ['socials', 'Social profiles', 'Instagram, Facebook, LinkedIn, TikTok, YouTube, X', true],
                    ['verify_emails', 'Verify email domains', 'MX check: Google Workspace / Microsoft 365 / other', false],
                    ['tech', 'Ad & tech signals', 'Meta pixel, Google Ads, analytics, CMS + intent score', false],
                ] as [$k, $label, $hint, $def]): ?>
                    <label class="toggle-card" :class="noWebsite && 'opacity-50 pointer-events-none'">
                        <input type="hidden" name="<?= $k ?>" value="0">
                        <input type="checkbox" name="<?= $k ?>" value="1" class="peer sr-only" <?= $oldBool($k, $def) ? 'checked' : '' ?>>
                        <span class="toggle"></span>
                        <span><span class="block text-sm font-medium text-slate-900"><?= e($label) ?></span><span class="block text-xs text-slate-500"><?= e($hint) ?></span></span>
                    </label>
                <?php endforeach; ?>
                <label class="toggle-card sm:col-span-2">
                    <input type="hidden" name="only_without_website" value="0">
                    <input type="checkbox" name="only_without_website" value="1" x-model="noWebsite" class="peer sr-only">
                    <span class="toggle"></span>
                    <span><span class="block text-sm font-medium text-slate-900">Only businesses <u>without</u> a website</span><span class="block text-xs text-slate-500">Great for selling website design. Disables website enrichment.</span></span>
                </label>
            </div>
        </section>
    </div>

    <!-- Sidebar: provider + limits + summary -->
    <div class="space-y-6">
        <section class="card p-5">
            <h2 class="section-title">Data source</h2>
            <div class="mt-3 space-y-2">
                <?php foreach ($providers as $pid => $label): ?>
                    <label class="flex cursor-pointer items-start gap-3 rounded-xl border p-3 transition" :class="provider === '<?= e($pid) ?>' ? 'border-indigo-500 bg-indigo-50/50 ring-1 ring-indigo-500' : 'border-slate-200 hover:border-slate-300'">
                        <input type="radio" name="provider" value="<?= e($pid) ?>" x-model="provider" class="mt-1 accent-indigo-600">
                        <span><span class="block text-sm font-medium text-slate-900"><?= e($label) ?></span><span class="block text-xs text-slate-500"><?= e($providerMeta[$pid][2] ?? '') ?></span></span>
                    </label>
                <?php endforeach; ?>
            </div>
            <?= field_error('provider') ?>
            <div class="mt-4">
                <div class="flex justify-between"><label class="label" for="mps">Results per search</label><span class="text-sm font-semibold text-indigo-700" x-text="Math.min(perSearch, cap)"></span></div>
                <input id="mps" type="range" name="max_per_search" min="20" :max="cap" step="20" x-model.number="perSearch" class="w-full accent-indigo-600">
                <p class="text-xs text-slate-500">Provider limit: <span x-text="cap"></span> per search.</p>
            </div>
            <div class="mt-4 grid grid-cols-2 gap-3">
                <div><label class="label" for="lang">Language</label><input id="lang" name="lang" class="input" maxlength="5" value="<?= e(old('lang', (string) setting('app.default_lang', 'en'))) ?>"><?= field_error('lang') ?></div>
                <div><label class="label" for="country">Country</label><input id="country" name="country" class="input" maxlength="2" placeholder="auto" value="<?= e(old('country', (string) setting('app.default_country', ''))) ?>"><?= field_error('country') ?></div>
            </div>
        </section>

        <section class="card p-5">
            <h2 class="section-title">Limits</h2>
            <div class="mt-3">
                <label class="label" for="max_time">Max run time</label>
                <select id="max_time" name="max_time" class="input">
                    <?php $mt = (int) old('max_time', (int) setting('jobs.max_time_default', 900)); foreach ([300 => '5 minutes', 900 => '15 minutes', 1800 => '30 minutes', 3600 => '1 hour', 7200 => '2 hours'] as $s => $l): if ($s > (int) setting('jobs.max_time_limit', 7200)) { continue; } ?>
                        <option value="<?= $s ?>" <?= $mt === $s ? 'selected' : '' ?>><?= $l ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php if ($previousJobs): ?>
                <div class="mt-4">
                    <label class="label" for="exclude">Skip places already found in</label>
                    <select id="exclude" name="exclude_jobs[]" multiple size="4" class="input text-sm">
                        <?php foreach ($previousJobs as $pj): ?>
                            <option value="<?= e($pj['id']) ?>"><?= e(str_limit($pj['name'], 40)) ?> (<?= (int) $pj['places_found'] ?>)</option>
                        <?php endforeach; ?>
                    </select>
                    <p class="mt-1 text-xs text-slate-500">Ctrl / ⌘ + click to select several. Only new leads are kept.</p>
                </div>
            <?php endif; ?>
        </section>

        <section class="card sticky top-20 p-5">
            <h2 class="section-title">Summary</h2>
            <dl class="mt-3 space-y-2 text-sm">
                <div class="flex justify-between"><dt class="text-slate-500">Keywords</dt><dd class="font-medium" x-text="keywordCount"></dd></div>
                <div class="flex justify-between"><dt class="text-slate-500">Searches</dt><dd class="font-medium" x-text="searches + (deep ? '+' : '')"></dd></div>
                <div class="flex justify-between"><dt class="text-slate-500">Max API calls</dt><dd class="font-medium" x-text="calls + (deep ? '+' : '')"></dd></div>
                <div class="flex justify-between"><dt class="text-slate-500">Max places</dt><dd class="font-medium" x-text="(searches * Math.min(perSearch, cap)).toLocaleString() + (deep ? '+' : '')"></dd></div>
            </dl>
            <button class="btn-primary mt-5 w-full justify-center py-2.5" :disabled="keywordCount === 0"><?= icon('play', 'w-4 h-4') ?> Start search</button>
            <p class="mt-2 text-center text-xs text-slate-400">Runs in the background — you can close this page.</p>
        </section>
    </div>
</form>
