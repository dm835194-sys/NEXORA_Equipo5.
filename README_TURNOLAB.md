# TurnoLab UPTEX

MVP de préstamos de equipo y laboratorios para el Reto 4 del UPTEX CHALLENGE. Laravel 13, Blade, Tailwind CSS y JavaScript; PHP 8.4.1 o posterior. Base SQLite para desarrollo local y MySQL para Railway.

## Abrir el sistema

En este equipo, abre `INICIAR_TURNOLAB.ps1` con PowerShell. La aplicación se sirve en http://127.0.0.1:8000. El proyecto se puede editar en Visual Studio Code. Si ya hay un servidor en el puerto 8000, usa la instancia existente.

En otro equipo: instala PHP, Composer y Node; ejecuta `composer install`, copia `.env.example` a `.env`, ejecuta `php artisan key:generate`, `php artisan migrate --force`, `php artisan db:seed --class=DemoSeeder`, `npm ci` y `npm run build`. Ejecuta `php artisan serve`, `php artisan queue:work` y `php artisan schedule:work` en terminales separadas.

## Cuentas de demostración

Contraseña compartida de la demo: `TurnoLab2026!`.

| Rol | Correo | Matrícula |
| --- | --- | --- |
| Estudiante | estudiante@turnolab.demo | DEMO-001 |
| Docente | docente@turnolab.demo | DOC-001 |
| Técnico | tecnico@turnolab.demo | TEC-001 |
| Coordinación | admin@turnolab.demo | ADM-001 |

Son cuentas ficticias, intencionalmente disponibles para demostrar los roles. No sirven como autenticación institucional. `DEMO_MODE=false` oculta los accesos rápidos y evita la carga automática de datos de ejemplo; antes de una operación real se deben sustituir las cuentas de demo, validar identidad institucional y formalizar privacidad y reglas.

## Recorrido para la exposición

1. Entra como estudiante y abre una reserva. Consulta el QR dinámico o reserva otra práctica con un kit disponible.
2. En otra sesión, entra como técnico, prepara el kit y valida el QR, la matrícula y los componentes. Si falta teléfono, registra una excepción asistida con motivo.
3. Confirma la entrega. El código queda consumido y los activos pasan a prestados.
4. Recibe el kit en Ventanilla. Una devolución sin revisión completa permanece indisponible; faltantes o fallas crean incidencia.
5. Aclara la incidencia si existe, completa la revisión y libera el kit.
6. Muestra bitácora, indicadores, simulación y el protocolo CSV de contingencia.

En demo se permite anticipar la entrega para mostrar el recorrido sin esperar a la clase. Con `DEMO_MODE=false`, se comprueba la ventana de entrega. Los indicadores con datos de prueba no acreditan metas operativas reales ni reducciones anuales.

## Avisos y contingencia

Los avisos se guardan en la bandeja de la plataforma. El programador los pone en cola al llegar a su hora, 15 minutos antes de recoger o devolver. El correo externo requiere configurar SMTP y `TURNOLAB_MAIL_ENABLED=true`; no se presenta como enviado si no está configurado.

El respaldo CSV se utiliza desde un único registro de autoridad y sólo con kits previamente asignados. El importador es transaccional: si una fila presenta conflicto, no aplica las nuevas filas del lote. Los folios ya conciliados se omiten. Las devoluciones offline quedan pendientes de revisión.

## Nube

`Dockerfile` compila la interfaz y prepara PHP/FrankenPHP. `docker/entrypoint.sh` espera la base, aplica migraciones y carga la demo sólo si corresponde. Supervisor mantiene web, cola y programador. Se usa MySQL persistente en la red privada; no se requiere publicar la base en Internet.

Variables: APP_KEY, APP_URL, APP_ENV=production, APP_DEBUG=false, APP_TIMEZONE=America/Mexico_City, APP_LOCALE=es, DB_CONNECTION=mysql, DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME, DB_PASSWORD, SESSION_DRIVER=database, CACHE_STORE=database, QUEUE_CONNECTION=database, LOG_CHANNEL=stderr y DEMO_MODE=true para el reto. Mantén las credenciales en las variables del proveedor, nunca en Git ni en el paquete de entrega.

El alojamiento consume recursos del plan o crédito contratado en Railway. Revisa los límites y consumo en su panel; la publicación no implica alojamiento gratuito permanente.

## Pruebas

Ejecuta `php artisan test` con SQLite en memoria. Las pruebas cubren permisos, asignación de activos, reservas incompatibles, tokens, devoluciones, revisión, avisos y conciliación idempotente. La carga de 185/220 solicitudes y la capacidad de dos técnicos requieren validación física adicional; el simulador es didáctico.

Referencias: [Laravel](https://laravel.com/docs), [Railway para Laravel](https://railway.com/guides/laravel), [FrankenPHP](https://frankenphp.dev/docs/docker/).
