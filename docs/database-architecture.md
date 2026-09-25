# Arquitectura de base de datos del MVP

## Objetivo

Soportar múltiples negocios y zonas independientes de música ambiental. El demo puede usar alrededor de 32 zonas, pero ni la base ni los modelos contienen ese límite. Esta fase implementa únicamente estructura y relaciones Eloquent.

## Tablas

| Tabla | Campos y propósito |
| --- | --- |
| users | Tabla estándar de Laravel más is_super_admin boolean default false. La bandera no implementa permisos por sí sola y no permite asignación masiva en User. |
| businesses | id, name, timestamps. Negocios. |
| business_user | business_id FK, user_id FK, timestamps; unique(business_id, user_id). Membresía múltiple. |
| zones | id, business_id FK, playlist_id FK nullable, name, output_channel string nullable, volume unsignedTinyInteger default 100, timestamps. |
| playlists | id, business_id FK obligatorio, name, timestamps. Cada playlist pertenece a un negocio. |
| songs | id, title, artist nullable, file_path, timestamps. Biblioteca global de canciones. |
| playlist_song | playlist_id FK, song_id FK, position unsignedInteger, timestamps; unique(playlist_id, song_id) e índice (playlist_id, position). |

Las tablas puente no requieren id propio. zone_playlist se elimina: cada zona tiene cero o una playlist configurada mediante zones.playlist_id. Una playlist puede estar configurada en varias zonas. Las tablas técnicas originales de Laravel se conservan.

## Relaciones y flujo

```text
Business
├── Zones
│   └── Playlist activa (opcional)
└── Playlists
    └── Songs (mediante playlist_song)

User
↓ membresía
Business
↓
Zone
↓ playlist configurada
Playlist
↓ canciones ordenadas
Song
```

Business también posee directamente sus Playlists; su propiedad no depende de que una zona las utilice. Las canciones pueden compartirse entre playlists de distintos negocios.

| Modelo | Relaciones Eloquent |
| --- | --- |
| User | businesses(): belongsToMany Business |
| Business | users(): belongsToMany User; zones(): hasMany Zone; playlists(): hasMany Playlist |
| Zone | business(): belongsTo Business; playlist(): belongsTo Playlist |
| Playlist | business(): belongsTo Business; zones(): hasMany Zone; songs(): belongsToMany Song |
| Song | playlists(): belongsToMany Playlist |

Las relaciones many-to-many usan withTimestamps(). Playlist.songs y Song.playlists exponen position mediante withPivot('position'). Playlist.songs ordena por position y luego por songs.id como desempate. Se recomienda empezar las posiciones en 1; no se exige que sean consecutivas o únicas, ni se implementa reordenamiento automático.

## Zone como unidad de reproducción

Zone es una unidad lógica de reproducción independiente. Posteriormente corresponderá aproximadamente a una instancia de Player dentro del Go Music Engine. playlist_id indica la playlist actualmente asignada/configurada, sin iniciar reproducción.

```text
Hotel Demo
├── Lobby       → Jazz         → 1-2
├── Restaurante → Mediterráneo → 3-4
└── Alberca     → Chill        → 5-6

Restaurante Demo
├── Salón       → Lounge       → 1-2
└── Terraza     → Chill        → 3-4

zone_id=1 / Lobby / playlist=Jazz / output_channel="1-2"
↓
Go Music Engine
↓
Player(zone_id=1)
↓
Dante 1-2
↓
Q-SYS
```

output_channel es solo una referencia de salida opcional, por ejemplo "1-2", "3-4" o "5-6". No es unique: diferentes negocios pueden reutilizar esos valores. No modela dispositivos, reservas ni conexiones Dante.

## Integridad, índices y eliminación

- business_user.business_id y user_id: cascadeOnDelete; se eliminan las membresías cuando desaparece cualquiera de sus extremos.
- zones.business_id y playlists.business_id: cascadeOnDelete; eliminar un negocio elimina sus zonas y playlists.
- zones.playlist_id: nullable y nullOnDelete; eliminar una playlist conserva sus zonas con playlist_id = null.
- playlist_song.playlist_id y song_id: cascadeOnDelete; se eliminan vínculos sin borrar la entidad del otro extremo.
- Las FK usan foreignId()->constrained(). Se indexan las FK de zones y playlists y la segunda FK de cada puente. La primera FK de cada puente queda cubierta por su índice unique compuesto.
- El índice (playlist_id, position) facilita consultar las canciones en orden. El unique (playlist_id, song_id) impide repetir una canción en la misma playlist.

Las FK impiden referencias inexistentes. El esquema solicitado con FK simples no comprueba que la playlist asignada y la zona pertenezcan al mismo negocio: la futura capa de escritura deberá validar esa coincidencia y los permisos de membresía. No se agregan triggers ni claves compuestas adicionales en este MVP.

volume se interpreta como porcentaje de 0 a 100; unsignedTinyInteger no impone por sí solo ese máximo. La futura capa de escritura deberá validar el rango. SQLite tampoco impone todos los rangos unsigned de otros motores.

## Migraciones y verificación

Se comprobó que las tablas de dominio estaban vacías. Se revirtieron únicamente las ocho migraciones originales del MVP antes de corregirlas; las dos sesiones existentes y las tablas técnicas se conservaron. Playlists se crea antes de zones para que su FK exista también en motores distintos de SQLite. La reversión elimina zones antes de playlists.

```sh
php artisan migrate
php artisan migrate:status
php artisan test
```

Las pruebas usan SQLite en memoria y verifican relaciones Eloquent, orden, defaults, duplicados, FK, índices, cascadas, nullOnDelete y más de 32 zonas. No se incluyen Resources de Filament, API, Music Engine, reproducción, S3, scheduler ni integración Dante/Q-SYS.
