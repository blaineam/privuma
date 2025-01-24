#!/bin/bash
export TS_IP=$(tailscale ip --4)
export TS_CERT=/usr/syno/etc/certificate/_archive/$(cat /usr/syno/etc/certificate/_archive/DEFAULT)/cert.pem
export TS_KEY=/usr/syno/etc/certificate/_archive/$(cat /usr/syno/etc/certificate/_archive/DEFAULT)/privkey.pem
cd /volume1/docker/privuma && export $(grep -v "^#" web/config/.env | xargs) && docker-compose up -d db && sleep 30 && docker-compose exec db mariadb-dump -u millers_privuma -p"$MYSQL_ROOT_PASSWORD" -h 127.0.0.1 millers_privuma > docker/images/mariadb/init.sql