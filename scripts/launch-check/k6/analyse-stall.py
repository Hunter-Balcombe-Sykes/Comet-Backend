#!/usr/bin/env python3
"""Join probe-stall.js's two outputs and find release waves.

    ./analyse-stall.py results/stall.json results/stall-trace.jsonl \
                       [results/stall-origin.jsonl] [--floor 800]

WHY THIS IS A COMMITTED SCRIPT. This analysis has been hand-rebuilt three times
(2026-08-03, 08-09, 08-10) and got a different answer each time, because the one
step that matters is easy to skip: k6 timestamps a request at COMPLETION, so a
slow request must be plotted at `completion - duration` before it means anything.
Read off completion alone, a queue drain looks like scattered unlucky requests --
which is exactly the conclusion the 2026-08-09 write-up reached and this script
overturns.

WHAT A WAVE IS. Requests whose COMPLETIONS cluster inside --wave ms while their
STARTS are spread much wider were not issued together; they were released
together. That is a queue draining, and it is the signature being hunted.

READING THE VERDICT. Cache status is checked BEFORE the controls, because a
`cf-cache-status: HIT` never reached PHP and settles the question on its own:
    all HITs ............. edge/transport. The origin was not involved. Final.
    partna + both ctrls .. this laptop's link or CPU. Not a Partna defect.
    partna + control_cf .. Cloudflare-side, both being fronted by CF.
    partna, ctrls clean .. origin-CAPABLE. Not proof -- read the origin log.
    control only ......... noise; ignore.

⚠️ THE CONTROLS UNDER-DETECT CONGESTION, so "controls clean" is weak evidence.
Measured 2026-08-10: profile 4,440 bytes on the wire, health 31, control_cf 206,
control_google 0. A one-packet response survives a congested link that a
four-packet response does not, so the controls can stay clean through exactly the
event that stalls the profile route. Confirm with the origin's own duration_ms
(`poll-origin-logs.sh slow`) before implicating Partna: on 2026-08-10 a request
the client saw as 9,475 ms was logged by the origin at **129 ms**.
"""

import argparse
import collections
import datetime
import json
import sys

PARTNA = ('profile', 'social', 'health')


def load_points(path):
    """Per-request timings from k6's JSON output — the authoritative clock."""
    rows = collections.defaultdict(dict)
    wanted = {
        'http_req_duration': 'dur', 'http_req_waiting': 'wait',
        'http_req_receiving': 'recv', 'http_req_blocked': 'blk',
        'http_req_connecting': 'conn', 'http_req_tls_handshaking': 'tls',
    }
    with open(path) as fh:
        for line in fh:
            try:
                d = json.loads(line)
            except json.JSONDecodeError:
                continue
            if d.get('type') != 'Point' or d.get('metric') not in wanted:
                continue
            tags = d['data']['tags']
            key = (tags.get('it'), tags.get('ep'))
            if key[0] is None:
                continue
            rows[key][wanted[d['metric']]] = d['data']['value']
            rows[key]['end'] = datetime.datetime.fromisoformat(d['data']['time'])
            rows[key]['status'] = tags.get('status')
    return rows


def load_trace(path):
    """Response-header classification from the console trace."""
    out = {}
    with open(path) as fh:
        for line in fh:
            line = line.strip()
            if not line:
                continue
            try:
                rec = json.loads(line)
            except json.JSONDecodeError:
                continue
            for q in rec.get('reqs', []):
                out[(rec.get('it'), q['ep'])] = q
    return out


def load_origin(path):
    """Deduped access-log entries; see poll-origin-logs.sh for the dedupe caveat."""
    seen, out = set(), []
    with open(path) as fh:
        for line in fh:
            line = line.strip()
            if not line:
                continue
            try:
                batch = json.loads(line)
            except json.JSONDecodeError:
                continue
            for e in batch:
                key = json.dumps(e, sort_keys=True)
                if key in seen or e.get('type') != 'access':
                    continue
                seen.add(key)
                out.append((
                    datetime.datetime.fromisoformat(
                        e['loggedAt'].replace('Z', '+00:00')),
                    e['data'],
                ))
    # Key on the timestamp alone — loggedAt is second-resolution, so ties are
    # common and a bare sort() falls through to comparing the dicts and raises.
    out.sort(key=lambda row: row[0])
    return out


def pct(values, p):
    if not values:
        return 0.0
    s = sorted(values)
    return s[min(len(s) - 1, int(len(s) * p))]


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('points')
    ap.add_argument('trace')
    ap.add_argument('origin', nargs='?')
    ap.add_argument('--floor', type=float, default=800.0,
                    help='ms; a request at or above this is "slow"')
    ap.add_argument('--wave', type=float, default=500.0,
                    help='ms; completions within this of each other are one wave')
    args = ap.parse_args()

    points, trace = load_points(args.points), load_trace(args.trace)
    reqs = []
    for key, v in points.items():
        if 'dur' not in v:
            continue
        t = trace.get(key, {})
        reqs.append({
            'it': key[0], 'ep': key[1],
            'end': v['end'],
            'start': v['end'] - datetime.timedelta(milliseconds=v['dur']),
            'dur': v['dur'], 'wait': v.get('wait', 0), 'recv': v.get('recv', 0),
            'blk': v.get('blk', 0), 'status': v.get('status'),
            'cf': t.get('cf'), 'age': t.get('age'), 'ray': t.get('ray'),
        })
    reqs.sort(key=lambda r: r['end'])

    if not reqs:
        sys.exit('no joined requests — was --console-output stale, or the run empty?')

    print(f'{len(reqs)} requests, {reqs[0]["end"]:%H:%M:%S} .. {reqs[-1]["end"]:%H:%M:%S}\n')
    by_ep = collections.defaultdict(list)
    for r in reqs:
        by_ep[r['ep']].append(r['dur'])
    print(f'{"endpoint":16} {"n":>5} {"p50":>8} {"p95":>8} {"max":>9}')
    for ep in sorted(by_ep, key=lambda e: (e.startswith('control'), e)):
        d = by_ep[ep]
        print(f'{ep:16} {len(d):5} {pct(d,.5):8.1f} {pct(d,.95):8.1f} {max(d):9.1f}')

    slow = [r for r in reqs if r['dur'] >= args.floor]
    print(f'\n{len(slow)} requests >= {args.floor:.0f}ms')
    if not slow:
        print('\nNo stall this run. The event landed in 2 of 5 historical runs; '
              'absence is not evidence it is fixed.')
        return

    waves, current = [], [slow[0]]
    for r in slow[1:]:
        if (r['end'] - current[-1]['end']).total_seconds() * 1000 <= args.wave:
            current.append(r)
        else:
            waves.append(current)
            current = [r]
    waves.append(current)

    origin = load_origin(args.origin) if args.origin else []

    for i, wave in enumerate(waves, 1):
        eps = {r['ep'] for r in wave}
        partna_reqs = [r for r in wave if r['ep'] in PARTNA]
        hit_partna = bool(partna_reqs)
        ctrls = {e for e in eps if e.startswith('control')}

        # A `cf-cache-status: HIT` was served entirely from the edge and never
        # reached PHP, so the origin is excluded by construction — whatever the
        # controls did. Checking the controls first gets this backwards: the
        # 2026-08-10 loaded run produced a wave of four requests, all HIT age=5,
        # that an earlier version of this script labelled "PARTNA ORIGIN" while
        # the origin log showed nothing but 23-42ms /api/health entries.
        edge_only = hit_partna and all(
            (r['cf'] or '').upper() == 'HIT' for r in partna_reqs)

        if len(wave) == 1:
            # One slow request is not a wave, and the control comparison says
            # nothing about it — the controls simply were not in flight. Calling
            # this "PARTNA ORIGIN" would manufacture a verdict out of a sample
            # of one, which is the failure mode this whole exercise is correcting.
            verdict = 'single slow request — not a wave, no verdict'
        elif edge_only:
            verdict = 'EDGE/TRANSPORT — every request was a cache HIT, origin never involved'
        elif hit_partna and len(ctrls) == 2:
            verdict = 'LOCAL — this laptop/link, not Partna'
        elif hit_partna and 'control_cf' in ctrls:
            verdict = 'CLOUDFLARE-SIDE — CF-fronted targets only'
        elif hit_partna and not ctrls:
            verdict = 'ORIGIN-CAPABLE — controls clean, but check the origin log below'
        else:
            verdict = 'CONTROL-ONLY — noise'

        spread = (wave[-1]['end'] - wave[0]['end']).total_seconds() * 1000
        starts = (max(r['start'] for r in wave)
                  - min(r['start'] for r in wave)).total_seconds() * 1000
        print(f'\n=== wave {i}: {len(wave)} requests — {verdict}')
        print(f'    starts spread {starts:.0f}ms, completions spread {spread:.0f}ms'
              f'{"  <-- RELEASED TOGETHER" if starts > spread * 2 else ""}')
        print(f'    {"started":13} {"completed":13} {"ep":15} {"dur":>8} {"wait":>8} '
              f'{"recv":>7} {"blk":>7}  cache')
        for r in wave:
            cache = (r['cf'] or '-') + (f' age={r["age"]}' if r['age'] else '')
            started = f'{r["start"]:%H:%M:%S.%f}'[:12]
            ended = f'{r["end"]:%H:%M:%S.%f}'[:12]
            print(f'    {started:13} {ended:13} {r["ep"]:15} {r["dur"]:8.1f} '
                  f'{r["wait"]:8.1f} {r["recv"]:7.1f} {r["blk"]:7.1f}  {cache}')

        if origin:
            lo = wave[0]['start'] - datetime.timedelta(seconds=2)
            hi = wave[-1]['end'] + datetime.timedelta(seconds=2)
            near = [(t, d) for t, d in origin if lo <= t <= hi]
            print(f'    -- origin access log, {lo:%H:%M:%S}Z..{hi:%H:%M:%S}Z '
                  f'({len(near)} entries)')
            for t, d in near:
                print(f'       {t:%H:%M:%S}Z  {d.get("duration_ms"):>6}ms  '
                      f'{d.get("status")}  {d.get("path")}')
            if not near:
                print('       (none — the stalled requests never reached PHP, '
                      'or the poller missed the window)')


if __name__ == '__main__':
    main()
