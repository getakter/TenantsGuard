<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Finishing…</title>
    <style>body{font:15px "Segoe UI",system-ui,sans-serif;display:grid;place-items:center;height:100vh;margin:0;color:#334155;background:#f8fafc}</style>
</head>
<body>
<p>Finishing… <a href="{{ $redirect }}">Continue</a></p>
<script>
    (function () {
        var to = @json($redirect);
        try {
            if (window.opener && !window.opener.closed && window.opener.location.origin === window.location.origin) {
                window.opener.postMessage({ type: 'tg-microsoft-done', redirect: to }, window.location.origin);
                window.close();
                return;
            }
        } catch (e) { /* opener on another origin */ }
        window.location.replace(to);
    })();
</script>
</body>
</html>
