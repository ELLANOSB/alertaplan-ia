# AlertaPlan IA · Ficha técnica (Hito 3 · MVP)

**Diplomado Internacional de IA aplicada a Ingeniería y Construcción · AECODE · Ed. 03 · Eje Desarrollo**
Versión 1.0 · noviembre de 2026 · Obra piloto: *Edificio Multifamiliar Claveles* (datos ficticios)

Sistema web local que une las dos propuestas del grupo en un solo proyecto oficial:

- **Seguimiento diario de obra (propuesta Claveles):** partes diarios de producción, PPC semanal, causas de incumplimiento propuestas por IA con cita y confianza, aprobación humana obligatoria e informe semanal de solo lectura para Gerencia.
- **Alertas tempranas (propuesta AlertaPlan):** carga de la planificación en Excel/CSV, fecha probable de término por actividad, margen, atraso, dependencias y semáforo explicable.

---

## 1. Acceso rápido

| Elemento | Valor |
|---|---|
| URL local | `http://127.0.0.1:8091` (Apache de FlyEnv) |
| Planeamiento (carga, revisa causas, aprueba) | `planeamiento@claveles.demo` |
| Producción (partes diarios) | `produccion@claveles.demo` |
| Gerencia (solo lectura, solo informes aprobados) | `gerencia@claveles.demo` |
| Contraseña de los tres usuarios demo | `Aecode2026!` |

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
| `datos/Prueba_errores.csv` | ID repetido, fecha 31/02 y ejecutado no numérico |
| `datos/ia_entrenamiento.json` | 140 frases sintéticas (20 por categoría) |
| `datos/ia_evaluacion.json` / `ia_evaluacion_ciega.json` | 30 y 21 notas etiquetadas para medir la IA |

Plantilla de carga: encabezados `ID, Actividad, Sector, Responsable, Unidad, Inicio, Fin, Total, Ejecutado, Rendimiento, Predecesoras, Restriccion, Liberacion, Fin real, Observacion` (obligatorios: ID, Actividad, Inicio, Fin, Total, Ejecutado, Unidad). Fechas AAAA-MM-DD, DD/MM/AAAA o fecha Excel (sistema 1900). Máx. 5 MB y 5000 actividades.

Todos los datos son **ficticios** y fueron creados por el equipo para la demo; la obra Claveles retoma el ejemplo de la plantilla de informe semanal del diplomado (sesión 08). No contienen datos personales reales.

## 6. Pruebas y resultados (ver `evidencias/`)

- **Motor e importación:** 23 casos (T1-T8 del Hito 2 y casos de borde: feriados, ciclos, duplicados, fecha Excel, calendario L-S, fecha de datos, CSV = XLSX). Rendimiento: 5000 actividades encadenadas en ≈3 s.
- **Flujo de extremo a extremo en la base de datos:** 13 verificaciones (importar, parte diario, bloqueo de aprobación, IA con cita, revisión, aprobación inmutable, semana siguiente con línea base conservada, auditoría).
- **Validación de salidas del LLM:** 11 casos con un LLM simulado (cita inventada, categoría inválida, timeout → respaldo local, modo manual).
- **IA local:** evaluación (usada para ajustar) 25/25 correctas, cobertura 92,6 %; **evaluación ciega** 7/7 correctas pero cobertura 36,8 % (se abstiene y deriva a la persona en 12 de 19 notas).
- **Línea base (back-test de la semana 46):** al corte del 07/11, la regla reactiva "fin vencido y sin terminar" no detecta ninguna de las 5 actividades que terminaron tarde; el motor marca 2 como atraso probable (sin falsas alarmas) y, contando *vigilar* y *bloqueada*, avisa de las 5 con 2 falsas alarmas.
- Capturas del recorrido en `evidencias/01_…10_*.png`.

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
├── app/               Motor, Importador, Repo, Web, Graficos, IA/, Vistas/
├── database/          schema.sql, instalar.php (semilla)
├── datos/             planes de prueba, corpus y evaluación de IA
├── tests/pruebas.php  pruebas automatizadas
├── evidencias/        resultados_pruebas.json y capturas
├── flyenv/            plantilla del sitio Apache (puerto 8091)
├── instalar.sh, .env.example
```

## 10. Registro de herramientas de IA y revisión humana

Se usó asistencia de IA (Claude) para programar, redactar la documentación y generar datos sintéticos. El equipo revisó la lógica contra cálculos manuales (casos T1-T8 y back-test), verificó el flujo completo en el navegador y documentó los resultados sin redondear a favor. La revisión del especialista AEC y las entrevistas con Planeamiento y Gerencia siguen pendientes (ver plan del Hito 3).
