# Corrected build for hackmanit/web-cache-vulnerability-scanner (upstream has
# no prebuilt image — see lib/common.sh require_wcvs_image()). Upstream's own
# Dockerfile (github.com/Hackmanit/Web-Cache-Vulnerability-Scanner/Dockerfile,
# tag 2.0.0) builds with `golang:latest` (glibc 2.34+) but runs on
# `debian:buster` (glibc 2.28) — the resulting binary fails at container
# start with "version `GLIBC_2.34' not found" (verified 2026-07-26). Fixed
# here with a static build (CGO_ENABLED=0) so the runtime base's glibc
# version is irrelevant; everything else matches upstream's Dockerfile.
FROM golang:latest AS builder
WORKDIR /go/src/app
COPY . .
RUN go get -d -v ./...
RUN CGO_ENABLED=0 go build -o wcvs

FROM debian:buster
RUN mkdir /app
COPY --from=builder /go/src/app/wcvs /wcvs
WORKDIR /app/
COPY wordlists/ wordlists/
CMD ["/wcvs"]
