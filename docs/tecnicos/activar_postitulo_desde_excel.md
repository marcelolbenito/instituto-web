# Activar postítulo / postgrado desde Excel

Archivo de ejemplo: `LISTADO COMPLETO POSTITULO.xlsx`  
Script: `tools/activar_postitulo_excel.php` (PHP, recomendado en servidor)  
Alternativa: `tools/activar_postitulo_excel.py` (requiere Python + openpyxl)

## Columnas del Excel

| Columna | Campo | Uso |
|---------|--------|-----|
| **A** | `articulo_id` | Concepto a vincular en `alumno_articulo` (ej. 6, 9, 10) |
| **B** | Apellido | Solo referencia / informe |
| **C** | Nombre | Solo referencia / informe |
| **D** | DNI | Empareja con `alumnos.documento` |

Fila 3 = encabezados; datos desde fila 4.

> La columna A es el **id del artículo** (`articulos.id`), no un id de `alumno_articulo` (esa tabla no tiene id propio: clave `alumno_id` + `articulo_id`).

## Qué hace

1. Busca cada DNI en `alumnos`.
2. Valida que `articulo_id` exista y esté activo.
3. **Activa** el alumno: `activo=1`, `estado_cuenta='activo'`, `fecha_inactivacion=NULL`, `tipo_alumno='postgrado'` (si existe la columna).
4. **INSERT IGNORE** en `alumno_articulo` si falta el vínculo.

**No hace:** desactivar otros alumnos, generar cuotas, crear pagos, purge Fox.

## Producción — checklist

### Antes

- [ ] Backup completo de la BD.
- [ ] Excel validado (111 filas en el listado actual; DNIs únicos).
- [ ] Migración `21` aplicada (`tipo_alumno`, rango postgrado en parámetros).

### Ejecución desde el navegador (recomendado en servidor sin PHP CLI)

1. Subir `public/activar_postitulo.php`, `src/ActivarPostituloExcel.php`, `src/XlsxMinimal.php` y el Excel en la raíz.
2. Ingresar como **administrador**.
3. **Utilitarios → Activar postítulo (Excel)** (o abrir `activar_postitulo.php`).
4. Revisar la vista previa en pantalla.
5. Marcar confirmación y **Aplicar activación**.

### Ejecución por SSH (PHP CLI)

Subir por FTP:

- `tools/activar_postitulo_excel.php`
- `src/ActivarPostituloExcel.php`
- `src/XlsxMinimal.php`
- `LISTADO COMPLETO POSTITULO.xlsx`

Usa la misma conexión que la app (`config/config.php` o variables de entorno del `bootstrap.php`). **No requiere Python ni pip.**

```bash
cd /ruta/instituto-web

# Backup
mariadb-dump -h 127.0.0.1 -u TU_USUARIO -p TU_BASE > backup_postitulo_$(date +%Y%m%d).sql

php tools/activar_postitulo_excel.php --dry-run
php tools/activar_postitulo_excel.php --yes
```

Ruta custom del Excel:

```bash
php tools/activar_postitulo_excel.php --excel="/ruta/LISTADO COMPLETO POSTITULO.xlsx" --dry-run
```

### Ejecución por SSH (Python, alternativa)

Subir por FTP:

- `tools/activar_postitulo_excel.py`
- `LISTADO COMPLETO POSTITULO.xlsx` (en la raíz del proyecto o ruta que uses en `--excel`)

```bash
cd /ruta/instituto-web
pip3 install openpyxl   # una vez

export MYSQL_HOST=127.0.0.1          # o el host de la BD
export MYSQL_USER=...
export MYSQL_PASSWORD=...
export MYSQL_DATABASE=instituto

# Backup
mariadb-dump -h "$MYSQL_HOST" -u "$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE" \
  > backup_postitulo_$(date +%Y%m%d).sql

python3 tools/activar_postitulo_excel.py --excel "LISTADO COMPLETO POSTITULO.xlsx" --dry-run
python3 tools/activar_postitulo_excel.py --excel "LISTADO COMPLETO POSTITULO.xlsx" --yes
```

`--yes` aplica las filas OK aunque queden DNIs sin encontrar (los 4 del informe local).

### Ejecución con Docker (desarrollo)

```bash
cd instituto-web

docker exec instituto-db mariadb-dump -u root -p"$MYSQL_ROOT_PASSWORD" instituto > backup_postitulo_$(date +%Y%m%d).sql

python tools/activar_postitulo_excel.py --excel "LISTADO COMPLETO POSTITULO.xlsx" --dry-run
python tools/activar_postitulo_excel.py --excel "LISTADO COMPLETO POSTITULO.xlsx" --yes
```

Sin `MYSQL_HOST`, el script usa `docker exec` y `MYSQL_DOCKER_CONTAINER` (default `instituto-db`).

### Después

- App → **Alumnos** → filtro **Activos**: buscar algunos DNIs del Excel.
- **Generar cuotas** (postgrado solo en meses del rango configurado), si corresponde al ciclo actual.
- Revisar filas con error `DNI no encontrado`: alta manual o corrección de documento.

## Errores frecuentes

| Mensaje | Acción |
|---------|--------|
| DNI no encontrado | Verificar `alumnos.documento` o dar de alta el alumno |
| articulo_id N no existe | Revisar `articulos` en la app (Utilitarios → Artículos) |
| articulo_id N inactivo | Activar el artículo o corregir el id en el Excel |
