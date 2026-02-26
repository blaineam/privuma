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

## WebDAV Access

Privuma exposes a read-only WebDAV endpoint at `/access` that lets you browse your media library organized by album using any WebDAV-compatible client (rclone, Finder, Windows Explorer, etc.).

### Virtual Filesystem Structure

Album names use `---` as a folder delimiter, so `Artists---Alice` becomes the nested path `Artists/Alice/`. This mirrors the folder hierarchy from the web viewer.

```
/access/
├── Albums/                  # Filtered media (blocked=0)
│   ├── Artists/
│   │   ├── Alice/
│   │   │   ├── photo.jpg
│   │   │   ├── photo.json   # Metadata sidecar
│   │   │   └── video.mp4
│   │   └── Bob/
│   │       └── ...
│   ├── Comics/
│   │   └── ...
│   └── ...
├── Favorites/               # Favorited media, grouped by source album
│   ├── Artists/
│   │   └── ...
│   └── ...
├── Unfiltered/              # Unfiltered media (blocked=1)
│   └── ...
├── Flash/                   # Flash (SWF) games and animations
│   ├── miniclip/
│   ├── armorgames/
│   └── ...
└── VR/                      # VR media (DeoVR/HereSphere compatible)
    ├── Studio-A/
    ├── Patreon/
    └── ...
```

Each media file has a `.json` sidecar containing its hash, duration, sound, score, and caption metadata.

### Authentication

Set `WEBDAV_USERNAME` and `WEBDAV_PASSWORD` in your `.env` file. The endpoint uses HTTP Basic Auth.

### Connecting with rclone

```bash
rclone config create privuma webdav \
  url "https://your-server:8989/access" \
  vendor other \
  user "your-username" \
  pass "your-password"
```

Then browse your library:

```bash
rclone lsd privuma:Albums/               # List top-level folders
rclone lsd privuma:Albums/Artists/       # List artists
rclone ls privuma:Albums/Artists/Alice/  # List files in an album
rclone copy privuma:Albums/Artists/Alice/ ./  # Download an album
rclone cat privuma:Albums/Artists/Alice/photo.json  # View file metadata
rclone lsd privuma:Flash/               # List flash categories
rclone lsd privuma:VR/                  # List VR folders
```

### Connecting with other clients

Use any WebDAV client with the following settings:

- **URL:** `https://your-server:8989/access`
- **Username/Password:** As configured in `.env`
- **macOS Finder:** Go > Connect to Server > `https://your-server:8989/access`
- **Windows Explorer:** Map Network Drive > `https://your-server:8989/access`

The endpoint is read-only. Write operations (upload, delete, move) will return `405 Method Not Allowed`.

## Contribute

Feel free to open a pr with an improvement you see fit. It can be anything, I will get to the PR when I have some free time.

## Thank You

This whole repo was a hobby project for the last few months and I have tested it decently well. It was made possible thanks to all of the google searches for various solutions to every issue I ran into. If you see some of your code here its because google helped me find it.
