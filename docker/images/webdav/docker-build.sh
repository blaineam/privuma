#!/bin/bash -e

export DOCKER_IMAGE=${DOCKER_IMAGE:-wheatstalk/docker-php-webdav:latest}

docker build -t $DOCKER_IMAGE .
docker-compose -f "docker-compose.test.yml" up --build --exit-code-from tester