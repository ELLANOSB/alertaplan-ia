# AlertaPlan IA · Ficha técnica (Hito 3 · MVP)

**Diplomado Internacional de IA aplicada a Ingeniería y Construcción · AECODE · Ed. 03 · Eje Desarrollo**
Versión 1.2 · noviembre de 2026 · Obra piloto: *Edificio Multifamiliar Claveles* (datos ficticios)

Sistema web local que une las dos propuestas del grupo en un solo proyecto oficial:

- **Seguimiento diario de obra (propuesta Claveles):** partes diarios de producción, PPC semanal, causas de incumplimiento propuestas por IA con cita y confianza, aprobación humana obligatoria e informe semanal de solo lectura para Gerencia.
- **Alertas tempranas (propuesta AlertaPlan):** carga de la planificación en Excel/CSV, fecha probable de término por actividad, margen, atraso, dependencias y semáforo explicable.

---

## 1. Acceso rápido

| Elemento | Valor |
|---|---|
| **Enlace público (Railway)** | **https://web-production-7049cceb.up.railway.app** |
| Código fuente (GitHub) | https://github.com/ELLANOSB/alertaplan-ia |
| URL local | `http://127.0.0.1:8091` (Apache de FlyEnv) |
| Cuentas de evaluación | Planeamiento, Producción y Gerencia. **Las credenciales se entregan solo por canal privado** (documentos de entrega en Classroom); no se publican en el repositorio ni en la pantalla de ingreso |
| Nuevos integrantes | Pantalla de ingreso → *Solicitar acceso* con el código de equipo que entrega Gerencia; la cuenta queda pendiente hasta su aprobación (sección 6 quater) |

> **Usa `127.0.0.1`, no `localhost`.** Si el navegador guardó HSTS para `localhost` (otro sitio local con HTTPS en el puerto 443), lo fuerza a `https://` y Apache responde *400 Bad Request* en un puerto HTTP. Con la IP no ocurre.

Alternativa sin Apache: `php -S 127.0.0.1:8091 -t public` dentro de la carpeta del proyecto.

## 2. Instalación y reproducción (macOS + FlyEnv)

Requisitos: FlyEnv con **PHP 8.3** (extensiones `pdo_mysql`, `zip`, `simplexml`, `mbstring`, `curl`) y **MySQL 8.4** encendidos. No necesita Composer, Node ni internet.

```bash
cd ~/Documents/DIPLOMADO/hito3_sistema/alertaplan-ia
./instalar.sh          # pide la contraseña root de MySQL de FlyEnv
```

El script: crea la base `alertaplan_ia` y el usuario `alertaplan`, crea las tablas, carga la demo recorriendo el mismo flujo de la aplicación (importar → partes diarios → IA → revisión → aprobación), ejecuta las pruebas y registra el sitio en el Apache de FlyEnv en el puerto 8091 (archivo `alertaplan-ia-local.conf`, con copia de respaldo de la configuración principal).

**Reiniciar la demo** antes de exponer: `php database/instalar.php` (borra y vuelve a cargar los datos).
**Ejecutar las pruebas:** `php tests/pruebas.php` → `evidencias/resultados_pruebas.json` (se ve en la página *Pruebas y resultados*).

### Instalación en otra computadora (Windows, Linux o macOS sin FlyEnv)

Sirve cualquier entorno con **PHP 8.1+** (extensiones `pdo_mysql`, `zip`, `xml`, `mbstring`, `curl`) y **MySQL 8 o MariaDB 10.6+**: XAMPP, Laragon, MAMP o servicios instalados a mano.

1. Crear la base y el usuario (en phpMyAdmin o en la consola de MySQL):
   ```sql
   CREATE DATABASE alertaplan_ia CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   CREATE USER 'alertaplan'@'localhost' IDENTIFIED BY 'Claveles.2026';
   GRANT ALL PRIVILEGES ON alertaplan_ia.* TO 'alertaplan'@'localhost';
   ```
2. Copiar `.env.example` como `.env` (ajustar `DB_HOST`, `DB_PORT` y la contraseña si cambian).
3. En la carpeta del proyecto: `php database/instalar.php` (tablas + datos de demostración).
4. Iniciar: `php -S 127.0.0.1:8091 -t public` y abrir **http://127.0.0.1:8091**.
5. Opcional: `php tests/pruebas.php` (48 casos), `php tests/pruebas_telegram.php` (27) y `php tests/pruebas_accesos.php` (28).

Si prefieres agregar el sitio desde la interfaz de FlyEnv: *Sitios → Nuevo*, raíz `…/alertaplan-ia/public`, PHP 8.3, puerto 8091.

## 3. Recorrido de la demo (flujo principal de extremo a extremo)

1. **Planeamiento** ingresa → *Tablero de obra*, semana 46 (07/11–14/11). PPC 50 %, 4 actividades con atraso probable, 1 bloqueada, 1 con datos por corregir.
2. Filtra *Atraso probable* y abre **CL-02**: el cálculo muestra restante, rendimiento, duración, fecha probable, atraso y margen. Las etiquetas separan *dato*, *cálculo* e *IA*.
3. Abre **CL-06** (gris): registra rendimiento 1,5 t/día → el motor recalcula y deja de bloquear.
4. *Cierre y causas IA* → **Proponer causas con IA**: cada compromiso no cumplido recibe categoría, cita literal y confianza. CL-09 se **abstiene** (sin dato suficiente).
5. Planeamiento **acepta** CL-02, CL-04 y CL-12 y **edita** CL-09 a *Ingeniería, planos y permisos* con comentario. Reprograma las cuatro con nueva fecha y acción.
6. El botón **Aprobar** solo se habilita cuando no quedan bloqueos. Al aprobar, el corte se congela (snapshot) y se publica el **Informe semanal**.
7. **Gerencia** ingresa: ve tendencia de PPC (67 % → 71 % → 50 %), causas acumuladas e informes aprobados. No puede cargar ni editar.
8. Multiobra: Planeamiento carga `datos/ViaAndina_S47_plan.xlsx` en *Cargar planificación* (mapeo automático, validación y vista previa antes de guardar). Producción registra un parte diario.
9. Casuísticas: cargar `datos/Plantilla_Casuisticas_S47.xlsx` en Claveles, semana 47 (corte 14/11, cierre 21/11, fecha de datos 21/11): 23 situaciones y la propuesta de la IA para cada tipo de causa (la hoja 2 del Excel explica el resultado esperado).
10. Canal móvil: con el bot de Telegram vinculado, `/estado`, `/atrasos`, `/actividad C03`, `/parte …` y envío del Excel (sección 6 bis).

## 4. Arquitectura implementada

| ID | Componente | Implementación | Archivo |
|---|---|---|---|
| C1 | Interfaz web por rol | PHP 8.3 + HTML/CSS/JS propio, sin CDN | `public/index.php`, `app/Vistas/` |
| C2 | Carga e importación | Lector CSV y XLSX (ZipArchive + SimpleXML), mapeo por alias | `app/Importador.php` |
| C3 | Base de datos | MySQL 8.4 (PDO, transacciones, claves foráneas) | `database/schema.sql`, `app/Repo.php` |
| C4 | Motor determinístico | Calendario laboral, feriados, dependencias FC, semáforo, PPC | `app/Motor.php` |
| C5 | Servicio de IA | Claude API opcional + Naive Bayes local; validación de salida | `app/IA/` |
| C6 | Revisión humana | Aceptar / editar / sin dato; bloqueo de aprobación | `Repo::revisarCausa`, `Repo::aprobar` |
| C7 | Informe e historial | Informe imprimible, CSV, snapshot JSON, auditoría | `app/Vistas/reporte.php`, `gerencia.php` |

**Flujo de información:** Excel/CSV → C2 valida → persona confirma → C3 guarda corte y línea base → C4 calcula → Producción registra partes → C4 recalcula desde la *fecha de datos* (último parte) → C5 propone causa con cita → validación automática → Planeamiento decide → reprograma y aprueba → C7 publica a Gerencia.

### Reglas del motor
- `restante = total − ejecutado`; `duración = techo(restante ÷ rendimiento)` en días laborables.
- El restante inicia el día laborable siguiente a la fecha de datos, al inicio planificado, a la predecesora (fin-a-inicio) o a la liberación de la restricción, lo que ocurra después.
- **Rojo**: fecha probable posterior al fin planificado. **Amarillo**: cumple con margen ≤ 2 días laborables (configurable por obra). **Verde**: mayor margen. **Naranja**: restricción o predecesora sin liberación. **Gris**: datos insuficientes (no se inventa fecha). **Azul**: terminada.
- **Compromiso** de la semana: fin planificado entre el corte y el cierre (o marcado a mano). **PPC** = compromisos terminados con fin real ≤ cierre ÷ compromisos.
- Pasar de semana no es atraso: se compara contra el fin de cada actividad. Reprogramar cambia el fin vigente y conserva la línea base.

### Estrategia de IA
- **Qué hace:** clasifica la causa de no cumplimiento a partir de la observación de campo en 7 categorías (materiales, mano de obra, ingeniería/permisos, calidad, clima, equipos, actividad previa) o *sin dato suficiente*.
- **Modos (`IA_MODO` en `.env`):** `auto` usa Claude si hay `ANTHROPIC_API_KEY` y, si no, el clasificador local; `local`; `manual` (sin IA).
- **Salida:** JSON con categoría, cita literal, confianza y justificación.
- **Validación automática:** esquema completo, categoría permitida, confianza en [0,1] y cita existente en la nota. Si falla o la API no responde → clasificador local. Si este no alcanza 0,50 de puntaje y 25 % de cobertura → se abstiene.
- **Revisión humana obligatoria:** nada llega a Gerencia sin decisión. Editar o marcar *sin dato* exige comentario. Todo queda en auditoría.
- **Límite de responsabilidad:** la IA no cambia fechas, semáforos ni PPC.

## 5. Datos

| Archivo | Uso |
|---|---|
| `datos/semilla_demo.json` | Obras, semanas 44-46 de Claveles y semana 46 de Vía Andina, con partes diarios |
| `datos/Claveles_S46_plan.xlsx` / `.csv` | Plan de la semana 46 al corte (misma información en dos formatos) |
| `datos/ViaAndina_S47_plan.xlsx` / `.csv` | Archivo para demostrar la carga en vivo |
| `datos/Plantilla_Casuisticas_S47.xlsx` | 23 casos (en fecha, vigilar, atraso, bloqueada, datos faltantes, terminada, feriado, cadenas) y una nota por tipo de causa IA; hoja 2 con el resultado verificado. Cargar en Claveles, semana 47, corte 14/11, cierre 21/11, fecha de datos 21/11 |
| `datos/Prueba_errores.csv` | ID repetido, fecha 31/02 y ejecutado no numérico |
| `datos/ia_entrenamiento.json` | 140 frases sintéticas (20 por categoría) |
| `datos/ia_evaluacion.json` / `ia_evaluacion_ciega.json` | 30 y 21 notas etiquetadas para medir la IA |

Plantilla de carga: encabezados `ID, Actividad, Sector, Responsable, Unidad, Inicio, Fin, Total, Ejecutado, Rendimiento, Predecesoras, Restriccion, Liberacion, Fin real, Observacion` (obligatorios: ID, Actividad, Inicio, Fin, Total, Ejecutado, Unidad). Fechas AAAA-MM-DD, DD/MM/AAAA o fecha Excel (sistema 1900). Máx. 5 MB y 5000 actividades.

Todos los datos son **ficticios** y fueron creados por el equipo para la demo; la obra Claveles retoma el ejemplo de la plantilla de informe semanal del diplomado (sesión 08). No contienen datos personales reales.

## 6. Pruebas y resultados (ver `evidencias/`)

- **Motor e importación:** 24 casos (incluye la plantilla de casuísticas) (T1-T8 del Hito 2 y casos de borde: feriados, ciclos, duplicados, fecha Excel, calendario L-S, fecha de datos, CSV = XLSX). Rendimiento: 5000 actividades encadenadas en ≈3 s.
- **Flujo de extremo a extremo en la base de datos:** 13 verificaciones (importar, parte diario, bloqueo de aprobación, IA con cita, revisión, aprobación inmutable, semana siguiente con línea base conservada, auditoría).
- **Validación de salidas del LLM:** 11 casos con un LLM simulado (cita inventada, categoría inválida, timeout → respaldo local, modo manual).
- **Bot de Telegram:** 27 casos con un Telegram simulado (vinculación por código, roles, consultas, parte diario, carga con confirmación, chats no autorizados).
- **Control de acceso:** 28 casos (código de equipo, cuenta pendiente sin acceso, aprobación solo por Gerencia, rechazo con motivo, códigos vencidos/agotados/revocados, política de contraseñas, bloqueo por intentos, mensajes que no revelan cuentas, suspensión que corta web y Telegram, rotación de la contraseña publicada, auditoría).
- **Total: 103 pruebas automatizadas aprobadas** (`php tests/pruebas.php`, `php tests/pruebas_telegram.php` y `php tests/pruebas_accesos.php`).
- **IA local:** evaluación (usada para ajustar) 25/25 correctas, cobertura 92,6 %; **evaluación ciega** 7/7 correctas pero cobertura 36,8 % (se abstiene y deriva a la persona en 12 de 19 notas).
- **Línea base (back-test de la semana 46):** al corte del 07/11, la regla reactiva "fin vencido y sin terminar" no detecta ninguna de las 5 actividades que terminaron tarde; el motor marca 2 como atraso probable (sin falsas alarmas) y, contando *vigilar* y *bloqueada*, avisa de las 5 con 2 falsas alarmas.
- Capturas del recorrido en `evidencias/01_…10_*.png`.

## 6 bis. Bot de Telegram

Canal móvil para consultar y alimentar el sistema. Funciona por *long polling*: el programa en la Mac (o en un VPS) pregunta a Telegram por mensajes nuevos, por lo que **no hay que abrir puertos ni tener dominio**.

| Comando | Qué hace | Roles |
|---|---|---|
| `/estado [obra]` | PPC, avance programado vs ejecutado, estados y prioridades | todos |
| `/atrasos [obra]` | Actividades en rojo o bloqueadas, con motivo y causa | todos |
| `/actividad C03` | Cálculo explicable, restricción, causa IA y decisión humana | todos |
| `/causas` · `/informe` | Causas de la IA y decisiones · informe semanal como archivo | todos (Gerencia: solo aprobados) |
| `/parte C03 1.5 [dd/mm] nota` | Parte diario: suma avance y recalcula | Producción, Planeamiento |
| archivo + `/cargar CLV 47 14/11/2026 21/11/2026 [fecha datos]` | Valida el Excel/CSV, muestra vista previa y pide **Importar / Cancelar** | Planeamiento |
| texto libre | Con `ANTHROPIC_API_KEY`: responde con los datos del sistema; sin ella, deriva al comando adecuado | todos |

**Cómo se suma otro integrante:** (1) tener cuenta aprobada por Gerencia (sección 6 quater); (2) ingresar a la web con su propio correo → *Bot de Telegram* → *Generar código*; (3) en Telegram abrir el bot y enviar `/vincular CÓDIGO`. Cada persona vincula su propio chat y el bot le responde con su rol.

**Seguridad:** solo chats privados vinculados a un usuario **activo** (código de un solo uso, 10 min, generado en *menú → Bot de Telegram*); cada chat hereda el rol del usuario; la carga requiere confirmación del mismo chat; la aprobación del corte solo se hace en la web; todo queda en auditoría. El token va únicamente en `.env`.

**Puesta en marcha (local):**
```bash
php database/migrar.php        # crea las tablas del bot en una base ya instalada
./bot/iniciar.sh               # inicia el bot en segundo plano (log: storage/telegram.log)
./bot/detener.sh               # lo detiene
php tests/pruebas_telegram.php # 27 pruebas con un Telegram simulado
```

**En un VPS:** copiar el proyecto, crear `.env` con la base y el token, `php database/instalar.php` (o `migrar.php`) y dejar `php bot/telegram.php` como servicio (systemd con `Restart=always`). Solo una instancia puede leer el bot a la vez: detén la de la Mac antes de iniciar la del VPS. Si luego se prefiere *webhook*, el VPS necesita HTTPS con dominio.

## 6 quater. Control de acceso

El sistema está publicado en internet, así que **nadie entra sin aprobación** y ninguna credencial aparece en la pantalla de ingreso ni en el código.

1. **Código de equipo:** Gerencia lo genera en *menú → Accesos del equipo* (vigencia de 1 a 14 días y número de usos). Se muestra una sola vez; en la base solo queda su hash. Se puede revocar.
2. **Solicitud:** la persona entra a *Solicitar acceso* desde la pantalla de ingreso con el código, su nombre, correo, cargo, el rol que pide y su contraseña. La cuenta queda **pendiente** y no puede ingresar.
3. **Aprobación:** Gerencia revisa que pertenezca al equipo, **asigna el rol final** (puede diferir del solicitado) y aprueba, o rechaza indicando el motivo.
4. **Suspensión:** Gerencia puede suspender o reactivar cuentas. La cuenta se revalida en cada pedido, así que la suspensión corta de inmediato la web y el bot de Telegram.

Controles adicionales: contraseñas con *bcrypt* (mín. 10 caracteres, letras y números, sin el correo ni claves comunes); bloqueo de la cuenta 15 minutos tras 5 intentos fallidos y límite por conexión (20 intentos de ingreso / 5 códigos inválidos por hora); mismo mensaje para correo inexistente o contraseña errónea; cookie de sesión `HttpOnly`, `SameSite=Lax` y `Secure` en HTTPS; cierre por inactividad (2 h); CSRF en todos los formularios; cada solicitud, decisión, bloqueo e ingreso queda en la auditoría.

**Cuentas demo:** la contraseña se define con la variable `DEMO_CLAVE` (Railway: variables del servicio web; local: `.env`). Si no se define, `instalar.php` genera una aleatoria y la guarda en `storage/credenciales_demo.txt` (excluido de git). `migrar.php` reemplaza automáticamente cualquier cuenta que aún use la contraseña publicada en versiones anteriores.

## 6 ter. Publicación en internet

### GitHub + Railway (enlace permanente)
El repositorio incluye `Dockerfile`, `railway.json` y `docker/entrypoint.sh`. El contenedor genera su `.env` desde las variables del servicio, espera a MySQL, instala la demo **solo si la base está vacía** (en los siguientes despliegues únicamente migra) y arranca Apache en el puerto que asigne Railway.

1. Subir el proyecto a GitHub (el `.gitignore` excluye `.env` y `storage/`: el token nunca sube).
2. En Railway: *New Project → Deploy from GitHub repo* (servicio **web**) y *+ New → Database → MySQL*.
3. Variables del servicio web: `MYSQLHOST`, `MYSQLPORT`, `MYSQLUSER`, `MYSQLPASSWORD`, `MYSQLDATABASE` como referencias a la base (`${{MySQL.MYSQLHOST}}`, etc.), `DEMO_CLAVE` (contraseña de las cuentas demo), `CONFIAR_PROXY=1` (cookie segura e IP real detrás del proxy de Railway) y `TELEGRAM_BOT_USUARIO` (nombre del bot para el enlace). Opcional: `ANTHROPIC_API_KEY`.
4. *Settings → Networking → Generate Domain* → enlace público `https://….up.railway.app`.
5. Bot (opcional): segundo servicio desde el mismo repositorio con las mismas variables + `APP_ROLE=bot` y `TELEGRAM_BOT_TOKEN`. Detener antes el bot de la Mac (solo una instancia puede leer el bot).
6. Reiniciar la demo en línea: consola del servicio web → `php database/instalar.php`.

### Plan B: túnel de Cloudflare desde la Mac
`brew install cloudflared` y luego `./tunel.sh` (puerto 8091 por defecto): imprime un enlace `https://….trycloudflare.com` que funciona mientras la Mac y FlyEnv estén encendidos. Cambia en cada ejecución.

## 7. Limitaciones

- Datos ficticios: falta validar con cortes reales anonimizados y con 3-5 usuarios de planeamiento y producción (instrumento pendiente de aplicar).
- Dependencias solo fin-a-inicio sin desfase; no calcula ruta crítica contractual, nivelación de recursos ni probabilidades de atraso.
- Productividad constante (rendimiento declarado). El avance programado es lineal por días laborables.
- El clasificador local tiene baja cobertura con vocabulario nuevo. El modo Claude no se midió en este hito (requiere API key y costo por consulta).
- Seguridad de demo: usuarios locales, sesión con CSRF y contraseñas con hash; sin SSO, HTTPS, cifrado de respaldos ni auditoría de seguridad de producción. El sitio solo responde a `localhost`.

## 8. Próximos pasos

1. Piloto con una obra real anonimizada durante 4 semanas y medir tiempo de consolidación semanal frente al proceso manual.
2. Ampliar el corpus con notas reales etiquetadas por un especialista y medir el modo Claude con el mismo conjunto ciego.
3. Lectura de partes diarios en PDF/foto (extracción con cita de página).
4. Despliegue en servidor con HTTPS, roles por obra y respaldos; notificaciones a responsables tras la aprobación.
5. Importar desde MS Project / Primavera P6 (XML) y comparar contra ruta crítica.

## 9. Estructura

```
alertaplan-ia/
├── public/            index.php (rutas), assets/ (CSS, JS, logos AECODE)
├── app/               Motor, Importador, Repo, Web, Accesos, Graficos, IA/, Telegram/, Vistas/
├── database/          schema.sql, instalar.php (semilla)
├── datos/             planes de prueba, corpus y evaluación de IA
├── bot/               telegram.php (servicio), iniciar.sh, detener.sh
├── tests/             pruebas.php, pruebas_telegram.php, pruebas_accesos.php
├── evidencias/        resultados_*.json y capturas
├── flyenv/            plantilla del sitio Apache (puerto 8091)
├── Dockerfile, railway.json, docker/   publicación en Railway
├── tunel.sh           enlace temporal con Cloudflare
├── instalar.sh, .env.example
```

## 10. Registro de herramientas de IA y revisión humana

Se usó asistencia de IA (Claude) para programar, redactar la documentación y generar datos sintéticos. El equipo revisó la lógica contra cálculos manuales (casos T1-T8 y back-test), verificó el flujo completo en el navegador y documentó los resultados sin redondear a favor. La revisión del especialista AEC y las entrevistas con Planeamiento y Gerencia siguen pendientes (ver plan del Hito 3).
