<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <title>Secure download</title>
    <style nonce="{{ $nonce }}">
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; font-family: sans-serif; background: #f7f7f5; color: #171717; }
        main { width: min(32rem, calc(100% - 2rem)); }
        p { line-height: 1.5; }
    </style>
</head>
<body>
<main aria-live="polite">
    <h1>Preparing your download</h1>
    <p id="status">Checking your secure link...</p>
</main>
<script nonce="{{ $nonce }}">
(() => {
    'use strict';
    const grantId = @json($grantPublicId);
    const fragment = window.location.hash.slice(1);
    window.history.replaceState(null, '', window.location.pathname);
    const token = new URLSearchParams(fragment).get('token');
    const status = document.getElementById('status');

    if (!token) {
        status.textContent = 'This download is unavailable.';
        return;
    }

    fetch('/api/downloads/' + encodeURIComponent(grantId) + '/authorize', {
        method: 'POST',
        headers: {
            'Accept': 'application/json',
            'Authorization': 'Bearer ' + token
        },
        credentials: 'same-origin'
    }).then(async (response) => {
        if (!response.ok) {
            throw new Error('unavailable');
        }
        const result = await response.json();
        window.location.replace(result.download_url);
    }).catch(() => {
        status.textContent = 'This download is unavailable.';
    });
})();
</script>
</body>
</html>
