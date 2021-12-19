#!/bin/bash -e

# Use xama5's installer script.
echo "Installing configuration"
/install.sh

# Perform environment substitutions on the /php.ini.template file.
envsubst </php.ini.template >/usr/local/etc/php/conf.d/webdav.ini

# Run any additional script hooks.
find /scripts.d/ -name "*.sh" -type f | while read SCRIPT; do
    echo "Running $SCRIPT"
    $SCRIPT
done

# Start the server.
echo "Starting docker-php-entrypoint $*"
docker-php-entrypoint $*