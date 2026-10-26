#!/bin/sh
# One-time Ubuntu 24.04 amd64 installation. Existing media configuration is preserved.
set -eu
umask 077
test "$(id -u)" = 0 || { echo 'Run as root'; exit 1; }
test "$(uname -m)" = x86_64 || { echo 'This installer requires amd64'; exit 1; }
test "$#" = 4 || { echo 'Usage: install.sh HOST CERTIFICATE PRIVATE_KEY APP_USER'; exit 1; }
media_host=$1
media_cert=$2
media_key=$3
media_app_user=$4
test -f "$media_cert" && test -f "$media_key"
id "$media_app_user" >/dev/null
test ! -e /etc/mlp-mediamtx/mediamtx.yml || { echo 'Media configuration already exists; use the documented update procedure'; exit 1; }
media_dir=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
media_tmp=$(mktemp -d)
trap 'rm -rf "$media_tmp"' EXIT HUP INT TERM
curl --noproxy '*' --fail --location --max-time 120 https://github.com/bluenviron/mediamtx/releases/download/v1.21.3/mediamtx_v1.21.3_linux_amd64.tar.gz -o "$media_tmp/media.tar.gz"
echo '0a2ea7eba96023631efa1bea7804823a9145b043b6646a8a05798d4ac24ed635  media.tar.gz' > "$media_tmp/checksum"
(cd "$media_tmp" && sha256sum -c checksum)
tar -xzf "$media_tmp/media.tar.gz" -C "$media_tmp" mediamtx
id mlp-media >/dev/null 2>&1 || useradd --system --no-create-home --shell /usr/sbin/nologin mlp-media
install -m 0755 "$media_tmp/mediamtx" /usr/local/bin/mlp-mediamtx
install -d -m 0750 -o root -g mlp-media /etc/mlp-mediamtx
install -d -m 0750 -o root -g "$media_app_user" /etc/mlp-evening
python3 - "$media_dir" "$media_host" "$media_cert" "$media_key" <<'PY'
import json, os, pathlib, re, secrets, sys
base, host, cert, key = sys.argv[1:]
if not re.fullmatch(r'[a-zA-Z0-9.-]+', host):
    raise SystemExit('Invalid host')
credentials = pathlib.Path('/etc/mlp-evening/stream-publish.json')
if credentials.exists():
    saved = json.loads(credentials.read_text())
    match = re.fullmatch(r'evening\?user=broadcaster&pass=([a-f0-9]{64})', saved['key'])
    if not match or saved['server'] != f'rtmps://{host}:1936':
        raise SystemExit('Existing credentials do not match; preserved without changes')
    password = match[1]
else:
    password = secrets.token_hex(32)
    with credentials.open('x') as output:
        json.dump({'server': f'rtmps://{host}:1936', 'key': f'evening?user=broadcaster&pass={password}'}, output)
config = pathlib.Path(base, 'mediamtx.sample.yml').read_text().replace('__PUBLISH_PASSWORD__', password)
config = config.replace('https://mlp-evening.ru', 'https://' + host).replace('https://www.mlp-evening.ru', 'https://www.' + host)
pathlib.Path('/etc/mlp-mediamtx/mediamtx.yml').write_text(config)
unit = pathlib.Path(base, 'mlp-mediamtx.service').read_text()
unit = re.sub(r'^LoadCredential=server.crt:.*$', 'LoadCredential=server.crt:' + cert, unit, flags=re.M)
unit = re.sub(r'^LoadCredential=server.key:.*$', 'LoadCredential=server.key:' + key, unit, flags=re.M)
pathlib.Path('/etc/systemd/system/mlp-mediamtx.service').write_text(unit)
pathlib.Path('/etc/mlp-mediamtx/tls-certificate-source').write_text(cert + '\n')
PY
chown root:mlp-media /etc/mlp-mediamtx/mediamtx.yml
chmod 0640 /etc/mlp-mediamtx/mediamtx.yml
chown root:"$media_app_user" /etc/mlp-evening/stream-publish.json
chmod 0640 /etc/mlp-evening/stream-publish.json
install -d -m 0755 /usr/local/lib/mlp-media
install -m 0755 "$media_dir/refresh-certificate.py" /usr/local/lib/mlp-media/refresh-certificate.py
install -m 0644 "$media_dir/mlp-media-certificate.service" "$media_dir/mlp-media-certificate.timer" /etc/systemd/system/
chmod 0644 /etc/systemd/system/mlp-mediamtx.service
systemd-analyze verify /etc/systemd/system/mlp-mediamtx.service /etc/systemd/system/mlp-media-certificate.service /etc/systemd/system/mlp-media-certificate.timer
systemctl daemon-reload
systemctl enable --now mlp-mediamtx.service mlp-media-certificate.timer
echo 'Media service installed. Install the nginx /live/ route and check the dashboard preview. Public player selection is unchanged.'
