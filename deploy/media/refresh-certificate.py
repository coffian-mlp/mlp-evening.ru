#!/usr/bin/python3
"""Refresh systemd TLS credentials after renewal, only while no stream is active."""
import json
import pathlib
import subprocess
import urllib.request

source_file = pathlib.Path('/etc/mlp-mediamtx/tls-certificate-source')
loaded = pathlib.Path('/run/credentials/mlp-mediamtx.service/server.crt')
source = pathlib.Path(source_file.read_text().strip())
if not loaded.exists() or source.read_bytes() == loaded.read_bytes():
    raise SystemExit(0)
try:
    opener = urllib.request.build_opener(urllib.request.ProxyHandler({}))
    with opener.open('http://127.0.0.1:8897/v3/paths/list', timeout=3) as response:
        data = json.loads(response.read(65536))
    if not isinstance(data.get('items'), list):
        raise ValueError('Unexpected media API response')
    if any(path.get('ready') for path in data['items']):
        print('Certificate renewal deferred until the publisher is offline')
        raise SystemExit(0)
except (OSError, ValueError):
    print('Certificate renewal deferred: media status is unavailable')
    raise SystemExit(1)
subprocess.run(['systemctl', 'try-restart', 'mlp-mediamtx.service'], check=True)
print('Media TLS credentials refreshed')
