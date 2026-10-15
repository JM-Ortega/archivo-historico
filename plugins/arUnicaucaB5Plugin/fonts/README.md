# Fuentes de la identidad (self-hosted)

Familias de la identidad visual del Archivo Histórico del Cauca: **Merriweather** (títulos y encabezados) y **Lato**
(interfaz). Se registran en `scss/_fonts.scss`; Webpack emite los ficheros a `dist/` y se sirven desde el propio AtoM, sin
peticiones a terceros. Solo subset `latin` (U+0000-00FF y adicionales: cubre el español). El navegador solo descarga una
cara cuando una página la usa.

Procedencia de los binarios: Google Fonts, API CSS2
(`family=Lato:ital,wght@0,400;0,700;1,400&family=Merriweather:wght@400;700`), descargados de `https://fonts.gstatic.com/`
(WOFF2 oficial, sin conversión local). Licencias: copia de `OFL.txt` de cada familia en el repositorio `google/fonts`
(`https://raw.githubusercontent.com/google/fonts/main/ofl/<familia>/OFL.txt`).

| Familia | Licencia | Copyright | Caras |
|---|---|---|---|
| Lato (v25) | SIL OFL 1.1 (`lato/OFL.txt`) | © 2010-2014 tyPoland Lukasz Dziedzic | 400, 400 cursiva, 700 |
| Merriweather (v33, variable) | SIL OFL 1.1 (`merriweather/OFL.txt`) | © 2020 The Merriweather Project Authors | 400–700 en un solo fichero |

| Fichero | Origen | SHA-256 |
|---|---|---|
| `lato/lato-latin-400.woff2` | `s/lato/v25/S6uyw4BMUTPHjx4wXg.woff2` | 918b7dc3e2e2d015c16ce08b57bcb64d2253bafc1707658f361e72865498e537 |
| `lato/lato-latin-400-italic.woff2` | `s/lato/v25/S6u8w4BMUTPHjxsAXC-q.woff2` | bca1d88ada544d9c80872d4da27133fab6d347361fa26e932b47ec9559088fd0 |
| `lato/lato-latin-700.woff2` | `s/lato/v25/S6u9w4BMUTPHh6UVSwiPGQ.woff2` | c447dd7677b419db7b21dbdfc6277c7816a913ffda76fd2e52702df538de0e49 |
| `merriweather/merriweather-latin-variable.woff2` | `s/merriweather/v33/u-4e0qyriQwlOrhSvowK_l5UcA6zuSYEqOzpPe3HOZJ5eX1WtLaQwmYiSeqqJ-k.woff2` | 4fbe8dd9c23b1fd62a988bb8a69b8e692d810f773d9ef6ebca2ba2760c7b11ee |

Open Sans (fuente de Dominion) no está aquí: la registra el `_typography.scss` nativo de `arDominionB5Plugin`. El theme ya no
la aplica a ningún elemento.
