FROM debian:jessie

RUN apt-get update \
    && apt-get install -y \
        curl \
        xmlstarlet

ADD test.sh /test.sh
ADD files files

ENTRYPOINT /test.sh