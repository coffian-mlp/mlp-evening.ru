#!/usr/bin/env python3
"""Explicit destructive test of the owned disposable DB, never production."""
import json
import subprocess
import sys
import time
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
TASK = ROOT / 'docs/tests/MLP-461'
WORK = TASK / 'workspace'
HELPER = ROOT / 'tests/helpers/daemon_environment.sh'
COMPOSE = ['docker', 'compose', '-p', 'mlp461', '-f', str(TASK / 'compose.yml')]
WORKERS = ['lyra_bot_worker.php', 'stream_monitor_worker.php']


def call(args, check=True):
    result = subprocess.run(args, cwd=ROOT, text=True, capture_output=True, timeout=100)
    if check and result.returncode:
        raise RuntimeError(f'{args}: {result.stdout} {result.stderr}')
    return result


def php(*args, check=True):
    return call([str_arg for str_arg in ['bash', str(HELPER), 'exec', 'php', *args]], check)


def events(worker):
    path = WORK / (worker + '.events')
    return [json.loads(line) for line in path.read_text().splitlines()] if path.exists() else []


def state():
    return json.loads(php('tests/helpers/daemon_fixture.php', 'state').stdout)


def await_state(predicate, seconds=45):
    deadline = time.monotonic() + seconds
    while time.monotonic() < deadline:
        result = php('tests/helpers/daemon_fixture.php', 'state', check=False)
        if result.returncode == 0:
            snapshot = json.loads(result.stdout)
            if predicate(snapshot):
                return snapshot
        time.sleep(1)
    raise AssertionError('worker recovery deadline exceeded')


def stop():
    (WORK / 'stop-supervisors').touch()
    deadline = time.monotonic() + 5
    while time.monotonic() < deadline:
        if all(len([e for e in events(w) if e['event'] == 'start']) == len([e for e in events(w) if e['event'] == 'exit']) for w in WORKERS):
            time.sleep(5.2)  # Let supervisors finish their restart delay before clearing the stop marker.
            return
        time.sleep(0.1)
    raise AssertionError('supervisors did not stop')


def main():
    mode = sys.argv[1]
    assert mode in ['before', 'after']
    assert (WORK / '.mlp461-owned').is_file()
    # Support stopping a preceding experiment; every child PID belongs to this lab.
    if any(events(w) for w in WORKERS):
        stop()
    (WORK / 'stop-supervisors').unlink(missing_ok=True)
    for worker in WORKERS:
        for suffix in ['.events', '.err', '.out']:
            (WORK / (worker + suffix)).unlink(missing_ok=True)
        call(COMPOSE + ['exec', '-d', 'php', 'php', 'tests/helpers/daemon_supervisor.php', worker])
    initial = state()
    before = await_state(lambda s: all(s[k] and s[k] != initial[k] for k in ['lyra_heartbeat', 'monitor_heartbeat']))
    assert len(before['runs']) == 1 and before['runs'][0]['armed'] == 1
    (WORK / 'probe-ready').unlink(missing_ok=True)
    probe = subprocess.Popen(COMPOSE + ['exec', '-T', 'php', 'php', 'tests/helpers/daemon_connection_probe.php'], cwd=ROOT, stdout=subprocess.PIPE, stderr=subprocess.STDOUT, text=True)
    deadline = time.monotonic() + 5
    while not (WORK / 'probe-ready').exists() and time.monotonic() < deadline:
        time.sleep(0.1)
    assert (WORK / 'probe-ready').exists()
    stopped = time.time()
    call(['bash', str(HELPER), 'stop-db'])
    print(f'{mode}: MySQL stopped; waiting 25 seconds', flush=True)
    time.sleep(25)
    outage_events = {w: events(w) for w in WORKERS}
    if mode == 'before':
        for w in WORKERS:
            assert len(outage_events[w]) == 1, outage_events
            text = (WORK / (w + '.err')).read_text()
            assert text.count('tick failed: mysqli_sql_exception') >= 2, text
    else:
        for w in WORKERS:
            exits = [e for e in outage_events[w] if e['event'] == 'exit']
            assert exits and all(e['code'] == 1 for e in exits), outage_events
            tick = 3 if w.startswith('lyra') else 10
            assert exits[0]['time'] - stopped <= tick + 2, outage_events
            assert not any(e['event'] == 'start_limit' for e in outage_events[w])
            starts = [e['time'] for e in outage_events[w] if e['event'] == 'start']
            assert all(b - a >= 5 for a, b in zip(starts, starts[1:])), starts
            assert 'code=2002' in (WORK / (w + '.err')).read_text()
    call(['bash', str(HELPER), 'start-db'])
    observed_code = probe.communicate(timeout=10)[0].strip()
    assert observed_code in ['mysqli_sql_exception code=1053', 'mysqli_sql_exception code=2006', 'mysqli_sql_exception code=2013'], observed_code
    online = await_state(lambda s: True)
    if mode == 'before':
        time.sleep(12)
        after = state()
        assert after['lyra_heartbeat'] == before['lyra_heartbeat']
        assert after['monitor_heartbeat'] == before['monitor_heartbeat']
        assert all(len(events(w)) == 1 for w in WORKERS)
    else:
        after = await_state(lambda s: all(s[k] != before[k] for k in ['lyra_heartbeat', 'monitor_heartbeat']))
        # Observe another full monitor interval; process must remain healthy without fresh errors.
        errors = {w: (WORK / (w + '.err')).read_text() for w in WORKERS}
        time.sleep(12)
        after = state()
        for w in WORKERS:
            assert errors[w] == (WORK / (w + '.err')).read_text()
            assert len([e for e in events(w) if e['event'] == 'start']) >= 2
        assert len(after['runs']) == 1
        assert after['runs'][0]['run_id'] == before['runs'][0]['run_id']
        assert after['runs'][0]['ended'] == 0 and after['runs'][0]['armed'] == 1
        assert after['runs'][0]['health'] == 'live'
        assert after['runs'][0]['lost_at'] is None and after['runs'][0]['incident_id'] is None
        assert after['runs'][0]['progress_key'] != before['runs'][0]['progress_key']
        assert after['incidents'] == before['incidents'] == 0
        assert after['deliveries'] == before['deliveries'] == 0
        telegram = (WORK / 'telegram-calls.log').read_text().splitlines()
        assert 'getUpdates' in telegram and set(telegram) <= {'getMe', 'getUpdates'}
    stop()
    report = {'mode': mode, 'observed_connection_error': observed_code, 'before': before, 'after': after,
              'events': {w: events(w) for w in WORKERS}, 'errors': {w: (WORK / (w + '.err')).read_text() for w in WORKERS}}
    (TASK / (mode + '.json')).write_text(json.dumps(report, indent=2) + '\n')
    print(f'{mode}: ALL PASS', flush=True)


if __name__ == '__main__':
    main()
