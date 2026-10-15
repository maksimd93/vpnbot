#!/bin/bash
# Host-level tweaks for this server: UDP buffers and Hysteria port hopping.
# Idempotent, safe to run again after updates.
set -euo pipefail
REPO=$(cd "$(dirname "$0")/.." && pwd)

install -m 0644 "$REPO/eternal/99-quic-buffers.conf" /etc/sysctl.d/99-quic-buffers.conf
sysctl -q -p /etc/sysctl.d/99-quic-buffers.conf

chmod +x "$REPO/eternal/porthop.sh"
sed "s|@REPO@|$REPO|g" "$REPO/eternal/hysteria-porthop.service" > /etc/systemd/system/hysteria-porthop.service
systemctl daemon-reload
systemctl enable hysteria-porthop.service > /dev/null 2>&1
systemctl restart hysteria-porthop.service

sysctl net.core.rmem_max net.core.wmem_max
"$REPO/eternal/porthop.sh" status
