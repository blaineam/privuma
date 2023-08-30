# Privuma

Privuma is a multimedia deduplication, compression and access endpoint that works with gphotos.wemiller.com and the ios app Lux Shield. It is a simple setup with docker compose that is extendable with jobs that can download content from around the internet. It consists of a customer php-fpm image that includes multimedia processing dependencies available to php via an exec call. 


# Structure

- mariadb: to keep track of deduplicated media
- nginx: to serve the endpoint for access media with the aforementioned apps.
- privuma-php: the custom image with php-fpm:7.4, python:3.9, ffmpeg, ImageMagick 7, and cron
- ssh-tunnel: a simple solution to use an external mysql db for a mirror/edge instance
- puppeteer: a chrome browser with a web based api to help with scraping cron jobs.

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


## Use gphotos.wemiller.com

If you navigate to the web app you can pass it your public endpoint and the AUTH_TOKEN you set in your `.env` file it will start loading the media from your Privuma instance. The gphotos.wemiller.com does not store your AUTH_TOKEN and it is only ever used for the initial handshake. All other communication is then handled with time based expired tokens.

## Lux Shield on iOS

Lux shield is a paid ios app that can also access a privuma endpoints media much the same way as the gphotos.wemiller.com site does.

## Contribute

Feel free to open a pr with an improvement you see fit. It can be anything, I will get to the PR when I have some free time.

## Thank You

This whole repo was a hobby project for the last few months and I have tested it decently well. It was made possible thanks to all of the google searches for various solutions to every issue I ran into. If you see some of your code here its because google helped me find it.
