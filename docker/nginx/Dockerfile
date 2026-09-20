# Nginx DEV de AtoM: imagen mínima y propia del proyecto.
#
# Solo contiene el contenido estático que Nginx sirve directamente (dist, images, css, js y los
# images/js/css de los plugins), copiado de la MISMA imagen `atom` contra la que corre PHP-FPM:
# así los assets con hash de `dist` coinciden siempre con los que referencia la aplicación y no
# hay copia manual ni bind del checkout. Es contenido derivado y reconstruible, no estado.
# La configuración (nginx.conf) no va en la imagen: se monta RO desde el repo (ver compose.yaml).
# No incluye código PHP de AtoM: `index.php` solo lo necesita PHP-FPM (en el contenedor `atom`).
#
# `atom` llega como contexto adicional (`additional_contexts: atom: service:atom` en Compose).
FROM nginx:1.28.3-alpine

# Ruta idéntica a la de `atom` (/atom/src): SCRIPT_FILENAME y los alias de X-Accel-Redirect
# se resuelven igual en ambos contenedores.
WORKDIR /atom/src

COPY --from=atom /atom/src/dist ./dist
COPY --from=atom /atom/src/images ./images
COPY --from=atom /atom/src/css ./css
COPY --from=atom /atom/src/js ./js
COPY --from=atom /atom/src/favicon.ico /atom/src/robots.txt ./

# Estáticos de los plugins (p. ej. arDominionB5Plugin/images): solo images/, js/ y css/, sin PHP.
RUN --mount=type=bind,from=atom,source=/atom/src/plugins,target=/plugins \
    mkdir plugins \
    && cd /plugins \
    && find . -type f \( -path '*/images/*' -o -path '*/js/*' -o -path '*/css/*' \) \
         ! -name '*.php' ! -path '*/CVS/*' ! -name '.gitignore' -exec cp --parents {} /atom/src/plugins/ \;
