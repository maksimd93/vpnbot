#!/usr/bin/env python3
"""Render the isolated server egress. Account and output must stay outside git."""
import json
import os
import sys

account = json.load(open(sys.argv[1], encoding="utf-8-sig"))
endpoint = account["endpoint"]["v4"].rsplit(":", 1)[0] + ":2408"
private_ranges = ["0.0.0.0/8", "10.0.0.0/8", "100.64.0.0/10", "127.0.0.0/8",
                  "169.254.0.0/16", "172.16.0.0/12", "192.168.0.0/16", "224.0.0.0/4",
                  "240.0.0.0/4", "::/128", "::1/128", "fc00::/7", "fe80::/10", "ff00::/8"]
config = {
    "log": {"loglevel": "warning"},
    "dns": {"servers": ["https://1.1.1.1/dns-query"], "queryStrategy": "UseIPv4"},
    "inbounds": [{"listen": "0.0.0.0", "port": 1080, "protocol": "socks", "tag": "ingress",
                  "settings": {"auth": "noauth", "udp": True, "ip": "10.10.0.20"}}],
    "outbounds": [{"protocol": "wireguard", "tag": "warp", "settings": {
        "secretKey": account["privateKey"],
        "address": [account["addresses"]["v4"] + "/32", account["addresses"]["v6"] + "/128"],
        "peers": [{"publicKey": account["publicKey"], "endpoint": endpoint,
                   "allowedIPs": ["0.0.0.0/0", "::/0"]}],
        "reserved": account["reserved"], "mtu": 1280, "noKernelTun": True,
        "domainStrategy": "ForceIPv4"}}, {"protocol": "blackhole", "tag": "block"}],
    "routing": {"domainStrategy": "AsIs", "rules": [
        {"type": "field", "ip": private_ranges, "outboundTag": "block"}]},
}
os.umask(0o077)
with open(sys.argv[2], "w", encoding="utf-8") as output:
    json.dump(config, output, indent=2)
os.chmod(sys.argv[2], 0o600)
