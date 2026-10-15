ARG image
FROM $image
# eternal: pinned AmneziaWG releases instead of whatever master was at build time
ARG AWG_GO_VERSION=v3.1.20260828
ARG AWG_TOOLS_VERSION=v3.1.20260812
COPY --from=golang:1.25-alpine /usr/local/go/ /usr/local/go/
ENV PATH="/usr/local/go/bin:${PATH}"
RUN apk add --no-cache --virtual .build-deps alpine-sdk git \
    && apk add iproute2 linux-headers iptables xtables-addons openssh wireguard-tools jq bash htop \
    && git clone --depth 1 --branch ${AWG_GO_VERSION} https://github.com/amnezia-vpn/amneziawg-go \
    && git clone --depth 1 --branch ${AWG_TOOLS_VERSION} https://github.com/amnezia-vpn/amneziawg-tools.git \
    && cd /amneziawg-go \
    && make install \
    && cd /amneziawg-tools/src \
    && make install WITH_WGQUICK=yes \
    && apk del .build-deps \
    && rm -rf /amneziawg-go \
    && rm -rf /amneziawg-tools \
    && rm -rf /root/go /root/.cache \
    && mkdir /root/.ssh
