# Sistema de Consentimientos — despliegue Docker (VPS / Coolify)

Migración del sistema que hoy corre en el hosting compartido
(`consentimientos.tecco.com.co`) a un contenedor Docker en el VPS
`72.60.29.194`, servido bajo **certificamos.com.co**. El sitio del
hosting no se toca.

## Contenido
- `Dockerfile` — imagen PHP 8.2 + Apache (extensiones pdo_mysql, mysqli, zip, gd;
  reinstala TCPDF 6.7.5 en el build si no viene en el código).
- `docker-compose.yml` — app + MariaDB 10.11 + volúmenes (`uploads`, `backups`, `db_data`).
- `app/` — código de la aplicación (63 archivos PHP + assets). `config.php` ya lee
  la conexión del entorno, con los valores actuales como respaldo.

> **El repositorio debe ser PRIVADO.** `app/config.php` conserva las credenciales
> de BD y la clave secreta de las firmas (`SECRET_KEY`) como respaldo, igual que en
> el sitio original. No lo publiques.

## Pasos de despliegue en Coolify

1. **Subir este código a un repositorio Git PRIVADO** (GitHub/GitLab/Gitea) y
   conectarlo en Coolify (Sources → tu GitHub).
2. En Coolify: **New Resource → Docker Compose** (o Dockerfile) apuntando al repo.
3. **Variables de entorno** del servicio `db` (MariaDB) — usar los MISMOS valores
   que están en `app/config.php`:
   - `DB_NAME`, `DB_USER`, `DB_PASS` → iguales a los de `config.php`.
   - `DB_ROOT_PASS` → una contraseña root nueva para MariaDB.
   La app toma `DB_HOST=db` por defecto y el resto desde `config.php`.
4. **Dominio:** asignar `certificamos.com.co` al servicio `app` (puerto 80). Coolify
   emite el SSL (Let's Encrypt) automáticamente. Requiere que el DNS de
   `certificamos.com.co` apunte a `72.60.29.194`.
5. **Desplegar.** Al primer arranque la BD queda vacía con su estructura.

## Carga de datos (NO va en el repositorio)

La base con pacientes y firmas se carga aparte, para no meter datos de salud en Git:

- Opción A (recomendada): una vez arriba el sitio en `certificamos.com.co`, entrar al
  panel admin y usar **Backups → Restaurar desde archivo**, subiendo el volcado
  `.sql.gz` más reciente. Reemplaza la BxD completa (incluye usuarios).
- Opción B: cargar el `.sql.gz` directamente en el contenedor MariaDB del VPS.

## Importante (integridad legal)
- La `SECRET_KEY` de `config.php` **no debe cambiar**: el hash SHA‑256 de cada firma
  depende de ella. Validado: 543/543 consentimientos verifican con la clave actual.
- Migrar también `assets/uploads` (logos) — ya incluidos — y la carpeta de backups
  como volumen.
