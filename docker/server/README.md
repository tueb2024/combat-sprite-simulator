# Server deployment templates (bin/jumphp deploy)

`bin/jumphp deploy` copies one of these folders to the server with the browser builds in `site/` and a `.env`
made from the project's `.env` (`JUMPHP_SERVER_MODE`, `JUMPHP_DOMAIN`, `JUMPHP_ACME_EMAIL`, `JUMPHP_TRAEFIK_*`),
then runs `docker compose up -d` there. `bin/jumphp deploy:setup` asks for the values.

- `frankenphp/`: FrankenPHP alone, ports 80 / 443, HTTPS certificates from Let's Encrypt by Caddy itself.
- `traefik/`: Traefik (ports 80 / 443, Let's Encrypt, HTTP -> HTTPS) in front of FrankenPHP (plain HTTP inside).
- `traefik-external/`: FrankenPHP only, joined to the network of a Traefik already running on the server.
- `traefik-passthrough/`: the same, for a Traefik without a working certificate resolver: Traefik passes TLS
  through (TCP router on `JUMPHP_TRAEFIK_ENTRYPOINT`) and FrankenPHP gets its own certificate (HTTP challenge
  through the port 80 entry point `JUMPHP_TRAEFIK_HTTP_ENTRYPOINT`).
