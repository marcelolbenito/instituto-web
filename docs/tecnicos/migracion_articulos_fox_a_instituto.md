# Migracion de articulos desde Fox a Instituto (paso a paso)

Objetivo: cargar articulos desde Fox (DBF/CSV) a la tabla `articulos` en MySQL/MariaDB, con validacion rapida en app.

## 1) Fuente de datos en Fox

Para articulos hoy el ETL base del proyecto toma como origen principal:

- `ABONCLIE.DBF` -> staging `staging_fox_abonclie`
  - `cod_artic` (codigo articulo legacy)
  - `detartic` (descripcion)

Nota: `ARTICULO.DBF` puede usarse despues para enriquecer importes/listas, pero el flujo minimo actual parte de `ABONCLIE`.

## 2) Precondiciones en base Instituto

Ejecutados al menos una vez:

- `sql/init/01_schema.sql`
- `sql/init/03_staging_fox.sql`
- `sql/init/04_schema_modo_operativo.sql`

Validacion rapida:

```sql
SHOW TABLES LIKE 'articulos';
SHOW TABLES LIKE 'staging_fox_abonclie';
DESCRIBE articulos;
DESCRIBE staging_fox_abonclie;
```

## 3) Exportar ABONCLIE desde Fox a CSV

Exportar a UTF-8 si es posible, con encabezados:

- `codclie`
- `cod_artic`
- `detartic`

Si no sale UTF-8, exportar ANSI y convertir antes de importar.

## 4) Cargar staging de articulos

Primero limpiar staging (opcional recomendado para corrida controlada):

```sql
TRUNCATE TABLE staging_fox_abonclie;
```

Importar CSV a `staging_fox_abonclie` por phpMyAdmin o con `LOAD DATA`.

Ejemplo `LOAD DATA`:

```sql
LOAD DATA LOCAL INFILE 'C:/ruta/ABONCLIE.csv'
INTO TABLE staging_fox_abonclie
CHARACTER SET utf8mb4
FIELDS TERMINATED BY ','
OPTIONALLY ENCLOSED BY '"'
LINES TERMINATED BY '\n'
IGNORE 1 LINES
(codclie, cod_artic, detartic);
```

## 5) Migrar solo articulos (sin correr ETL completo)

Usar este bloque para alta/actualizacion de `articulos` desde staging:

```sql
UPDATE staging_fox_abonclie
SET detartic = NULLIF(TRIM(detartic), '')
WHERE procesado = 0;

INSERT INTO articulos (codigo_legacy, detalle, importe_referencia, activo)
SELECT DISTINCT
  a.cod_artic,
  COALESCE(a.detartic, CONCAT('Articulo legacy ', COALESCE(a.cod_artic, 0))),
  0.00,
  1
FROM staging_fox_abonclie a
WHERE a.procesado = 0
  AND a.error_msg IS NULL
  AND a.cod_artic IS NOT NULL
ON DUPLICATE KEY UPDATE
  detalle = VALUES(detalle),
  activo = 1;
```

Opcional: marcar staging como procesado luego de validar:

```sql
UPDATE staging_fox_abonclie
SET procesado = 1
WHERE procesado = 0
  AND error_msg IS NULL
  AND cod_artic IS NOT NULL;
```

## 6) Verificaciones SQL post-migracion

```sql
-- Cantidad total de articulos
SELECT COUNT(*) AS total_articulos FROM articulos;

-- Ultimos articulos por codigo legacy
SELECT id, codigo_legacy, detalle, importe_referencia, activo
FROM articulos
ORDER BY id DESC
LIMIT 20;

-- Duplicados potenciales de detalle
SELECT detalle, COUNT(*) c
FROM articulos
GROUP BY detalle
HAVING c > 1
ORDER BY c DESC, detalle;

-- Filas staging con problemas
SELECT *
FROM staging_fox_abonclie
WHERE error_msg IS NOT NULL
LIMIT 50;
```

## 7) Validacion funcional en app

Ir a `public/articulos.php` y revisar:

- listado visible con `codigo_legacy` y `detalle`;
- sin descripciones vacias;
- se puede editar un articulo migrado y guardar.

## 8) Siguiente paso recomendado

Luego de confirmar `articulos`, continuar con:

1. relacion `alumno_articulo` (ABONCLIE completo),
2. rubros e importes por lista (si se completa desde `ARTICULO.DBF` o carga manual),
3. prueba de generacion de cuotas con datos reales.

