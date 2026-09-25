# Corrección de subidas de audio en Windows con Herd

## Síntomas confirmados

- La subida temporal de un WAV de 449 KB devolvía HTTP 422: `File upload error - unable to create a temporary file`.
- Tras configurar únicamente `upload_tmp_dir`, Livewire fallaba en `TemporaryUploadedFile.php` con `stream_get_meta_data(): Argument #1 ($stream) must be of type resource, false given`.
- En el segundo caso, `tmpfile()` no conseguía crear el temporal. Se necesitan ambas configuraciones de PHP: la de recepción de subidas y la de temporales generales.

## Configuración local

1. Identificar el PHP que ejecuta la aplicación y su configuración con `php --ini`. Si PHP no está en PATH, usar la ruta del ejecutable de Herd. El PHP del servidor debe cargar la configuración que se modifica.
2. Guardar una copia del `php.ini` activo.
3. Crear una carpeta fuera del directorio público, con permisos de escritura para el usuario que ejecuta PHP. Por ejemplo, `%USERPROFILE%/.config/herd/tmp/uploads`.
4. Configurar ambas directivas en `php.ini` usando la ruta absoluta real. Sustituir `TU_USUARIO`; PHP no debe recibir ese marcador ni `%USERPROFILE%` literalmente:

```ini
upload_tmp_dir = "C:/Users/TU_USUARIO/.config/herd/tmp/uploads"
sys_temp_dir = "C:/Users/TU_USUARIO/.config/herd/tmp/uploads"
```

5. Reiniciar `php artisan serve`. Si se sirve mediante Herd, reiniciar el servicio PHP correspondiente.
6. Recargar el formulario y volver a seleccionar el audio.

El cambio es local al entorno PHP y puede afectar a otros proyectos que utilicen ese mismo `php.ini`. No se debe modificar `vendor/`, desactivar validaciones ni guardar el archivo `php.ini` completo en Git. Descargar este repositorio no aplica automáticamente estas directivas al equipo.

## Verificación realizada

- La misma subida WAV pasó de HTTP 422 a HTTP 200 después de configurar `upload_tmp_dir`.
- Tras configurar también `sys_temp_dir`, una comprobación HTTP en el servidor local verificó `tmpfile()` y la construcción de `Livewire\Features\SupportFileUploads\TemporaryUploadedFile` sin excepción, con HTTP 200.
- Se retiraron los archivos y el diagnóstico temporal de las pruebas.
- Estas comprobaciones cubren la recepción y creación de temporales; no verifican el guardado completo de una canción ni una subida MP3 real.

Los límites de tamaño y los tipos MIME son controles independientes. Estos errores se reprodujeron con un archivo pequeño y no se resolvían aumentando el límite de subida.
