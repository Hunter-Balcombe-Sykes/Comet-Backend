<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Partna mail preview</title>
    <style>
        * { box-sizing: border-box; margin: 0; }
        body { font: 14px/1.5 -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; display: flex; height: 100vh; color: #1d1d1f; background: #f5f5f7; }
        nav { width: 260px; overflow-y: auto; border-right: 1px solid #e5e5e7; background: #fff; padding: 16px 0 32px; flex-shrink: 0; }
        nav h1 { font-size: 15px; padding: 0 16px 12px; }
        nav h2 { font-size: 11px; text-transform: uppercase; letter-spacing: .04em; color: #86868b; padding: 14px 16px 4px; }
        nav a { display: block; padding: 5px 16px; color: #1d1d1f; text-decoration: none; font-size: 13px; }
        nav a:hover { background: #f5f5f7; }
        nav a.active { background: #e8f0fe; color: #1367fb; font-weight: 500; }
        main { flex: 1; display: flex; flex-direction: column; min-width: 0; }
        header { display: flex; align-items: center; gap: 8px; padding: 10px 16px; border-bottom: 1px solid #e5e5e7; background: #fff; }
        header .title { font-weight: 600; margin-right: auto; }
        header button { font: inherit; font-size: 13px; padding: 5px 12px; border: 1px solid #d2d2d7; border-radius: 6px; background: #fff; cursor: pointer; }
        header button.on { background: #1d1d1f; color: #fff; border-color: #1d1d1f; }
        .frame-wrap { flex: 1; display: flex; justify-content: center; overflow: auto; padding: 24px; }
        iframe { border: 1px solid #e5e5e7; border-radius: 8px; background: #fff; width: 100%; max-width: 720px; height: calc(100vh - 100px); transition: max-width .2s; }
        iframe.mobile { max-width: 375px; }
        .empty { margin: auto; color: #86868b; }
    </style>
</head>
<body>
    <nav>
        <h1>Mail preview</h1>
        @foreach ($groups as $group => $entries)
            <h2>{{ $group }}</h2>
            @foreach ($entries as $key => $entry)
                <a href="#{{ $key }}" data-key="{{ $key }}">{{ $entry['label'] }}</a>
            @endforeach
        @endforeach
    </nav>
    <main>
        <header>
            <span class="title" id="title">Pick an email</span>
            <button id="width-toggle" type="button">Mobile</button>
            <button id="dark-toggle" type="button">Dark mode</button>
            <button id="open-raw" type="button">Open raw</button>
        </header>
        <div class="frame-wrap">
            <iframe id="frame" title="Email preview"></iframe>
        </div>
    </main>
    <script>
        const frame = document.getElementById('frame');
        const title = document.getElementById('title');
        const widthToggle = document.getElementById('width-toggle');
        const darkToggle = document.getElementById('dark-toggle');
        const openRaw = document.getElementById('open-raw');
        let dark = false;
        let current = null;

        function load() {
            if (!current) return;
            frame.src = `/dev/emails/${current}${dark ? '?dark=1' : ''}`;
        }

        document.querySelectorAll('nav a').forEach(a => a.addEventListener('click', () => {
            document.querySelectorAll('nav a').forEach(x => x.classList.remove('active'));
            a.classList.add('active');
            current = a.dataset.key;
            title.textContent = a.textContent;
            load();
        }));

        widthToggle.addEventListener('click', () => {
            frame.classList.toggle('mobile');
            widthToggle.classList.toggle('on');
        });
        darkToggle.addEventListener('click', () => {
            dark = !dark;
            darkToggle.classList.toggle('on', dark);
            load();
        });
        openRaw.addEventListener('click', () => current && window.open(`/dev/emails/${current}${dark ? '?dark=1' : ''}`));

        const initial = location.hash.slice(1);
        const link = initial && document.querySelector(`nav a[data-key="${CSS.escape(initial)}"]`);
        (link || document.querySelector('nav a')).click();
    </script>
</body>
</html>
