# Cuota

Sistema de ventas programadas, pagos y saldos de clientes por concesionario.

La aplicación está en la raíz del repositorio. Ejecutar Composer, Artisan, npm y las pruebas desde esta carpeta. Configurar Apache para servir `public/`.

Para instalar: ejecutar `composer install`, copiar `.env.example` como `.env`, configurar una base de datos vacía, ejecutar `php artisan key:generate`, `php artisan migrate --seed`, `npm ci` y `npm run build`.

Para ejecutar las pruebas: `php artisan test`. Las pruebas adicionales usan `phpunit.mysql.xml` y `playwright.config.js`; requieren bases MySQL separadas llamadas `ventas_programada_test` y `ventas_programada_browser`.

El repositorio conserva código, migraciones, pruebas, archivos de dependencias y sus versiones bloqueadas. Las dependencias instaladas, `.env`, datos locales, respaldos y resultados de pruebas quedan fuera del Git.
