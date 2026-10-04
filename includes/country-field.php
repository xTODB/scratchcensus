<?php
// Country dropdown for the Filter window (users, static and dynamic). Expects $country and $countryData
// from index.php. Pick a country, press Apply: the list shows only Scratchers from there.
$countryList = $countryData['list'] ?? [];
$countryKnown = false;
foreach ($countryList as [$cn, $cc]) if (strcasecmp($cn, $country) === 0) { $countryKnown = true; break; }
?>
<div class="field">
    <label for="f-country">Country</label>
    <select id="f-country" name="country">
        <option value="">All countries</option>
        <?php if ($country !== '' && !$countryKnown): ?><option value="<?= e($country) ?>" selected><?= e($country) ?></option><?php endif; ?>
        <?php foreach ($countryList as [$cn, $cc]): ?>
            <option value="<?= e($cn) ?>"<?= strcasecmp($cn, $country) === 0 ? ' selected' : '' ?>><?= e($cn) ?> (<?= number_format($cc) ?>)</option>
        <?php endforeach; ?>
    </select>
</div>
