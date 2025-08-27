FROM trafex/php-nginx:latest

# Setup pdftk, need to use java version as not packaged for alpine linux
USER root
RUN apk update
RUN apk upgrade
RUN apk add bash
RUN apk add openjdk8
RUN apk add pandoc-cli
RUN apk add texlive
RUN apk add texmf-dist-fontsrecommended
RUN apk add texmf-dist-latexrecommended
RUN wget https://gitlab.com/pdftk-java/pdftk/-/jobs/924565145/artifacts/raw/build/libs/pdftk-all.jar
RUN mv pdftk-all.jar /usr/local/bin/pdftk.jar

COPY data/pdftk /usr/local/bin
RUN chmod 775 /usr/local/bin/pdftk

# Copy website source
COPY data/var/www/html/ /var/www/html
COPY data/anonymiseHVF.sh /usr/local/bin/
COPY data/usr/local/Resources /usr/local/Resources

# Fix to ensure linux line endings not windows
RUN sed -i 's/\r$//' /usr/local/bin/anonymiseHVF.sh
RUN sed -i 's/\r$//' /usr/local/bin/pdftk

USER nobody