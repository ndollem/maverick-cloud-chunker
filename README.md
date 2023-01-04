# maverick cloud chunker
Convert news article data into maverick rows ready

## Installation

Add the Functions Framework and other dependencies listed on `composer.json` file using
[Composer][composer].

```sh
composer install
```

[composer]: https://getcomposer.org/

## Run your function locally

After completing the steps under **Installation** run the following commands:

```sh
export FUNCTION_TARGET=maverickChunker
php -S localhost:8080 vendor/bin/router.php
```

OR use composer 

```sh
composer start
```

Open `http://localhost:8080/` in your browser


## Run your function in a container

After completing the steps under **Installation**, build the container using the example `Dockerfile`:

```
docker build . \
    -f Dockerfile \
    -t maverick-chunker
```

Run the cloud functions framework container:

```
docker run -p 8080:8080 \
    -e FUNCTION_TARGET=maverickChunker \
    maverick-chunker
```

Open `http://localhost:8080/` in your browser

## Making request

This function use POST method to read data and process it before return in json format.

```sh
curl -X POST -F 'content=[$content_value]' http://localhost:8080/
```

**NOTE**: make sure to put all article content value in content parameters, including the paging page value