#!/bin/bash
export TS_IP=$(tailscale ip --4)
export TS_CERT=/usr/syno/etc/certificate/_archive/$(cat /usr/syno/etc/certificate/_archive/DEFAULT)/cert.pem
export TS_KEY=/usr/syno/etc/certificate/_archive/$(cat /usr/syno/etc/certificate/_archive/DEFAULT)/privkey.pem
cd /volume1/docker/privuma
docker-compose up -d