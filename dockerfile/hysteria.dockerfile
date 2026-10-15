# eternal: pinned Hysteria release (stateless reset, cert hot-reload, port hopping fixes)
FROM tobyxdd/hysteria:v2.13.0
RUN apk add --no-cache openssh \
    && mkdir -p /root/.ssh
ENV ENV="/root/.ashrc"
ENTRYPOINT []
