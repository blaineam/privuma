#!/bin/bash -e

export DOCKER_REPOSITORY=${DOCKER_REPOSITORY:-wheatstalk/docker-php-webdav}
export VERSION=${VERSION:-0.0.0}

[ ! -z "${DOCKER_USERNAME}" ] && docker login --username "$DOCKER_USERNAME" --password "$DOCKER_PASSWORD" $DOCKER_SERVER
source <(curl -s https://raw.githubusercontent.com/misterjoshua/docker-push-semver-bash/master/docker_push_semver.sh)
dockerPushSemver $DOCKER_REPOSITORY $VERSION