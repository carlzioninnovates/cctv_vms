# Multi-arch: build with  docker buildx build --platform linux/amd64,linux/arm64 .
FROM python:3.12-slim
ARG FRP_VERSION=0.71.1
RUN apt-get update && apt-get install -y --no-install-recommends nut-client curl ca-certificates \
 && arch=$(dpkg --print-architecture) \
 && curl -fsSL "https://github.com/fatedier/frp/releases/download/v${FRP_VERSION}/frp_${FRP_VERSION}_linux_${arch}.tar.gz" \
    | tar xz --strip-components=1 -C /usr/local/bin "frp_${FRP_VERSION}_linux_${arch}/frpc" \
 && apt-get purge -y curl && rm -rf /var/lib/apt/lists/*
WORKDIR /opt/vms-agent
COPY pyproject.toml .
COPY agent ./agent
RUN pip install --no-cache-dir .
# host network: needed for WS-Discovery multicast and LAN access to NVRs
CMD ["vms-agent", "run"]
