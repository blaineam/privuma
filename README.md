# Privuma

Privuma is a multimedia deduplication, compression and access endpoint that works with a web based viewer. It is a simple setup with docker compose that is extendable with jobs that can download content from around the internet. It consists of a customer php-fpm image that includes multimedia processing dependencies available to php via an exec call.

# Structure

- mariadb: to keep track of deduplicated media
- nginx: to serve the endpoint for access media with the aforementioned apps.
- privuma-php: the custom image with php-fpm:8.1, python:3.9, ffmpeg, ImageMagick 7

## Setup

1. git clone the repo to a server with a lot of bandwidth and storage space, a NAS works great
2. update the `dotenv-example.env` to have your values for your server and rename it to just `.env`
   - you will have to copy that file to 2 locations `web/config/.env` and `.env`
3. ssh into your server and run `docker-compose --env-file ./web/config/.env up -d`
4. add your folders and media to `web/data/privuma` with structures like this:
   - .../privuma
   - .../privuma/cats/cute001.png
   - .../privuma/cats/adorbs002.jpg
   - .../privuma/dogs/goof.mp4
   - .../privuma/dogs/happy.gif

## Building docker containers:

```bash
docker buildx create --name mybuilder --use --bootstrap
docker buildx build --push --platform linux/amd64,linux/arm64 --tag cenode/privuma-php:latest --target base docker/images/php
```

## Contribute

Feel free to open a pr with an improvement you see fit. It can be anything, I will get to the PR when I have some free time.

## Thank You

This whole repo was a hobby project for the last few months and I have tested it decently well. It was made possible thanks to all of the google searches for various solutions to every issue I ran into. If you see some of your code here its because google helped me find it.
