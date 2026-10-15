#!/bin/sh
# Hysteria port hopping: forward a UDP port range straight to the vpnbot
# hysteria container. DNAT to the container (not REDIRECT to the published
# port) so packets bypass docker-proxy and hysteria still sees the real
# client address. The published port (44333) keeps working as before.
# usage: porthop.sh add|del|status
set -eu
RANGE="${HY_HOP_RANGE:-20000:20999}"
TARGET="${HY_HOP_TARGET:-10.10.0.17:443}"
IFACE="${HY_HOP_IFACE:-$(ip -4 route show default | awk '{print $5; exit}')}"
RULE="-i $IFACE -p udp -m udp --dport $RANGE -m comment --comment vpnbot-hysteria-porthop -j DNAT --to-destination $TARGET"

case "${1:-}" in
    add)
        iptables -t nat -C PREROUTING $RULE 2> /dev/null || iptables -t nat -I PREROUTING 1 $RULE
        ;;
    del)
        while iptables -t nat -C PREROUTING $RULE 2> /dev/null; do
            iptables -t nat -D PREROUTING $RULE
        done
        ;;
    status)
        iptables -t nat -L PREROUTING -n -v --line-numbers | grep -E '^num|vpnbot-hysteria-porthop' || true
        ;;
    *)
        echo "usage: $0 add|del|status" >&2
        exit 1
        ;;
esac
