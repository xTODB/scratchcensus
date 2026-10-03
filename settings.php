<?php
require_once __DIR__ . '/functions.php';
$pageTitle = 'Settings - ScratchCensus';
$pageDesc = 'Choose how ScratchCensus looks for you.';
$navActive = 'settings';
require __DIR__ . '/includes/layout-top.php';
?>
<div class="prose">
<h1>Settings</h1>
<p class="setnote">These are saved in this browser only. Nothing is sent to ScratchCensus.</p>

<div class="card">
    <h2>Pictures</h2>
    <p>Show Scratcher profile pictures and studio thumbnails next to names. They are loaded straight from Scratch, so ScratchCensus stores no images. Turning them off also stops your browser from loading them.</p>
    <div class="setrow">
        <label for="pics-on">Show pictures</label>
        <input type="checkbox" id="pics-on">
    </div>
    <div class="setrow">
        <label for="pics-size">Picture size</label>
        <select id="pics-size">
            <option value="s">Small</option>
            <option value="m">Medium</option>
            <option value="l">Large</option>
        </select>
    </div>
    <p class="setnote" id="pics-saved" style="visibility: hidden;">Saved.</p>
</div>
</div>
<script>
(function() {
    var on = document.getElementById('pics-on'), size = document.getElementById('pics-size'), note = document.getElementById('pics-saved');
    var h = document.documentElement;
    on.checked = h.getAttribute('data-img') === 'on';
    size.value = h.getAttribute('data-imgsize') || 'm';
    function save() {
        try { localStorage.setItem('census_pics', JSON.stringify({ on: on.checked, size: size.value })); } catch (e) {}
        h.setAttribute('data-img', on.checked ? 'on' : 'off');
        h.setAttribute('data-imgsize', size.value);
        note.style.visibility = 'visible';
    }
    on.addEventListener('change', save);
    size.addEventListener('change', save);
})();
</script>
<?php require __DIR__ . '/includes/layout-bottom.php'; ?>
