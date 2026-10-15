#!/bin/bash
# Rolls back install.sh: removes port hopping and restores default UDP buffers.
set -uo pipefail
systemctl disable --now hysteria-porthop.service 2> /dev/null
rm -f /etc/systemd/system/hysteria-porthop.service
systemctl daemon-reload
rm -f /etc/sysctl.d/99-quic-buffers.conf
sysctl -q -w net.core.rmem_max=212992 net.core.wmem_max=212992
echo "port hopping removed, UDP buffers back to 212992"
