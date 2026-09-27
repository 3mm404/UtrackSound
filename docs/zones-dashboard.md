# Dashboard de Zonas

## Cambios

- `/admin/zones` muestra tarjetas de reproductor en un grid adaptable de una a tres columnas.
- Se reemplazó la presentación de Table por una vista custom de `ManageZones`; ya no se muestran filas, selección masiva ni el selector “Per page”.
- Se conserva `ManageRecords` para mantener el query del Resource, el scope por negocio, las autorizaciones y el registro de Actions.
- La paginación se presenta con controles propios y el dashboard se actualiza cada cinco segundos.
- Cada tarjeta muestra zona, equipo, disponibilidad, canción, artista, controles, playlist, volumen, estado y vigencia del reporte.
- El menú por tarjeta mantiene Ver, Órdenes, Editar y Eliminar; Ajustes y los controles de transporte siguen llamando las Actions existentes.

## Causa Y CSS

- El panel no cargaba el CSS compilado de las vistas personalizadas, por lo que las utilidades Tailwind del Blade no tenían efecto.
- Se añadió un theme Vite de Filament v5, con fuentes `@source` explícitas para las vistas y el grid responsive.
- `AdminPanelProvider` registra el theme y `vite.config.js` lo incluye en el build.

## Validación

- `npm run build` y `php artisan view:cache` finalizaron correctamente.
- Suite PHP: 72 pruebas pasaron, 1 quedó omitida; creación, edición, transporte y ajustes tienen cobertura.
- En navegador se verificaron el layout responsive, estilos oscuros, ausencia de tabla/checkboxes y apertura de los modales principales.
- No se enviaron comandos al Music Engine real.