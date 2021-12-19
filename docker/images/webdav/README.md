![CI Status](https://img.shields.io/github/workflow/status/misterjoshua/docker-php-webdav/CI)

# docker-php-webdav
WebDAV (SabreDAV) Docker Image with authentication running on the official php images.

## About

This is a working WebDAV http server image for docker. This image hosts SabreDAV (php-based) as a WebDAV backend, which supports most WebDAV clients without the fuss and bugs of associated with Apache HTTP Server and nginx WebDAV solutions.

Features:
* Supports Windows mounting via Windows Explorer
* Works with WinSCP and other OSS products
* Provides the Sabre web interface when WebDAV isn't detected
* It's a drop-in replacement for `xama5/docker-nginx-webdav`
* Most importantly: It just works

This image was originally forked from `xama5/docker-nginx-webdav`, but the following changes were made:
* Changed to a multi-staged build to simplify building
* Switched to building with the official php images
* Added `composer.json` and `composer.lock` to the project so that the project gets GitHub auto-patching for security
* Allows the user to skip the permission-fixing script by setting the `SKIP_PERMISSIONS_FIX` environment variable to something other than "no"
* Introduced automated build testing via GitHub actions and docker-compose

## Getting started

You can run this container the following way:

````
docker run -d \
           -e WEBDAV_USERNAME=admin \
           -e WEBDAV_PASSWORD=admin \
           -p 8080:80 \
           -v /path/to/your/files:/var/webdav/public \
           wheatstalk/docker-php-webdav
````

This will start a new webdav instance on `http://localhost:8080` with the given username and password for authentication.
