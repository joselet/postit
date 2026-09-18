# Notas — tablero de notas flotantes

Aplicación web mínima (PHP + JS vanilla, sin frameworks ni build step) que muestra un
"tablero" con notas tipo post-it: ventanas flotantes que se pueden crear, arrastrar,
redimensionar, renombrar y editar, y que se guardan automáticamente en el servidor.

## Cómo funciona

- **`index.php`** — Sirve la página. Lee el parámetro de query `?tablero=nombre`
  (por defecto `default`) y lo inyecta en el JS de cliente. Todo el comportamiento
  (crear, arrastrar, redimensionar, renombrar, autoguardado, detección de notas
  fuera de pantalla) vive en el `<script>` de este mismo archivo — no hay JS externo.
- **`api.php`** — Backend mínimo con dos acciones por query string:
  - `GET api.php?action=load&tablero=X` → devuelve el JSON completo del tablero `X`.
  - `POST api.php?action=save&tablero=X` → sobrescribe el JSON completo del tablero `X`
    con el body de la petición.
  El nombre de `tablero` se sanea con una regex (`[^a-zA-Z0-9_-]`) para construir el
  nombre de archivo de forma segura.
- **`notes_<tablero>.json`** — Un archivo por tablero (p.ej. `notes_default.json`),
  con un array de notas. Cada tablero es completamente independiente; no hay concepto
  de usuario ni de propiedad, cualquiera que conozca o adivine el nombre del tablero
  puede leerlo y sobrescribirlo.
- **`1_primer_index.html`** — Prototipo inicial (sin backend, todo en un solo HTML).
  Se mantiene como referencia histórica; el desarrollo activo está en `index.php`.

### Modelo de datos de una nota

```json
{
  "text": "contenido del textarea",
  "title": "título mostrado en la barra superior",
  "left": "120px",
  "top": "80px",
  "width": "300px",
  "height": "200px"
}
```

Todos los campos son opcionales al cargar: si faltan `left`/`top` la nota aparece en
una posición aleatoria, si falta `width`/`height` se usa el tamaño por defecto CSS
(300×200), y si falta `title` se usa "📝 Nota". Esto mantiene compatibilidad con
tableros guardados antes de añadir cada campo.

### Autoguardado

No hay botón "Guardar": cada interacción relevante llama a `saveAllNotes()`, que
serializa **todas** las notas del tablero y sobrescribe el JSON completo en el
servidor. Se dispara en:

- perder el foco de un `<textarea>` (dejar de escribir en una nota),
- soltar el ratón tras arrastrar una nota,
- dejar de redimensionar una nota (vía `ResizeObserver`, con debounce),
- confirmar un cambio de título,
- pulsar "Traer notas a la vista" (ver más abajo).

### Notas fuera de pantalla

Las posiciones se guardan en coordenadas absolutas de documento, pensadas para la
pantalla donde se crearon. Si el tablero se abre luego en una pantalla más pequeña
(u otro ordenador), una nota puede caer fuera del viewport visible: sigue ahí (el
`body` permite scroll) pero es fácil no darse cuenta y darla por perdida. La app
detecta este caso (`isNoteOffscreen`) y muestra un botón "📍 Traer notas a la vista"
que recoloca en cascada solo las notas afectadas, encogiéndolas si además son más
grandes que la pantalla actual.

## Ejecutar en local

Requiere PHP con servidor embebido (sin dependencias ni `composer`):

```bash
php -S localhost:8000
```

Y abrir `http://localhost:8000/index.php` (o `?tablero=nombre` para otro tablero).

## Limitaciones actuales / deuda técnica

- **Sin autenticación ni control de acceso**: cualquiera que conozca el nombre de un
  tablero puede leerlo y machacarlo.
- **Guardado de todo el array en cada cambio**: dos pestañas/usuarios editando el
  mismo tablero a la vez se pisan el uno al otro (el último `save` gana, sin merge
  ni detección de conflicto).
- **Sin control de concurrencia en el archivo**: `file_put_contents` no bloquea, así
  que dos guardados simultáneos podrían intercalarse en casos raros.
- **z-index no persistido**: el orden de apilado se resetea al recargar.
- **Solo ratón**: arrastrar/redimensionar no soportan touch, poco usable en tablet/móvil.
- **`api.php` acepta cualquier JSON como body de `save`** sin validar su forma; un
  cliente malicioso podría guardar cualquier estructura arbitraria en el archivo.

## Roadmap: multi-usuario y sincronización

Ideas para evolucionar el proyecto hacia los casos de uso planteados (varios
usuarios, varios tableros por usuario, tableros compartidos, y varias sesiones
sincronizadas sobre el mismo tablero). Ninguna de estas piezas está implementada
todavía — son la base sobre la que planificar los próximos cambios:

1. **Modelo de datos relacional.** El esquema de archivos planos (`notes_X.json`)
   no escala a usuarios/permisos/relaciones. El paso natural es **SQLite**: no
   necesita servidor de base de datos nuevo (sigue siendo "solo un archivo" como
   ahora), pero da transacciones, bloqueo de escritura correcto y consultas
   relacionales. Tablas aproximadas:
   - `users` (id, email, password_hash, ...)
   - `boards` (id, owner_user_id, name, created_at)
   - `board_members` (board_id, user_id, role) — para tableros compartidos
   - `notes` (id, board_id, text, title, left, top, width, height, z_index,
     updated_at, updated_by)

2. **Autenticación.** Sesiones PHP nativas (`session_start()`) + `password_hash`/
   `password_verify`, o un login por token si en el futuro se separa frontend/backend.
   Sencillo de añadir sobre el stack PHP actual, sin nuevas dependencias.

3. **Autorización por tablero.** `api.php` debería comprobar, antes de `load`/`save`,
   que el usuario autenticado es owner o miembro (`board_members`) del tablero
   solicitado — hoy no hay ninguna comprobación de este tipo.

4. **Guardado granular en vez de "todo el array".** Para que compartir tablero y
   sincronizar sesiones sea viable sin pisarse constantemente, conviene sustituir
   "guardar el array completo" por endpoints por nota: `POST /notes` (crear),
   `PATCH /notes/{id}` (mover, redimensionar, renombrar, editar texto),
   `DELETE /notes/{id}`. Esto reduce muchísimo la superficie de conflicto entre
   sesiones simultáneas.

5. **Sincronización en tiempo real entre sesiones.** Con guardado granular, dos
   opciones razonables sin reescribir el stack:
   - **Polling corto** (cada 2-5s, comparando un `updated_at`/versión del tablero)
     — más simple, encaja con el `fetch` que ya se usa, pero no es instantáneo.
   - **Server-Sent Events** desde `api.php` (una conexión persistente por sesión que
     empuja cambios) — más trabajo pero notificación inmediata, y sigue siendo PHP
     puro (no requiere WebSockets ni un proceso Node aparte).
   En ambos casos, conviene guardar un timestamp/versión por nota para poder aplicar
   concurrencia optimista (avisar o fusionar si dos sesiones editan la misma nota a
   la vez) en vez de que gane ciegamente el último `save`.

6. **UI de gestión de tableros.** Sustituir el `?tablero=nombre` manual por un
   selector/listado de tableros propios y compartidos tras el login.

## Otras mejoras que valdría la pena considerar

- **Autoguardado periódico mientras se escribe**, no solo al perder el foco del
  `textarea` — hoy un cierre de pestaña o un cuelgue del navegador puede perder los
  últimos cambios de una nota en edición.
- **Persistir el z-index** de cada nota (además de al crearla, al traerla al frente
  con un clic) para que el orden de apilado sobreviva a un recargo de página.
- **Soporte táctil** (`touchstart`/`touchmove`/`touchend`) para arrastrar y
  redimensionar notas en tablet/móvil, ya que hoy solo se manejan eventos de ratón.
- **Exportar/importar un tablero como JSON** — backup manual sencillo mientras no
  haya multiusuario ni versionado.
- **Validar el body en `api.php?action=save`** (forma esperada de cada nota) para
  no persistir a ciegas cualquier JSON que llegue en la petición.
- **Papelera o confirmación al cerrar una nota** — hoy el botón "✖" borra sin
  posibilidad de deshacer.
